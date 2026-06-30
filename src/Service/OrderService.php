<?php

namespace SimPay\WooCommerce\Service;

use SimPay\SDK\AmountVerifier;
use SimPay\SDK\IpnPayload;
use SimPay\SDK\PaymentStatus;

class OrderService
{
    private RefundsService $refunds;

    public function __construct(RefundsService $refunds)
    {
        $this->refunds = $refunds;
    }

    /**
     * Handle "transaction:status_changed" event.
     */
    public function handleTransactionStatusChanged(IpnPayload $ipn): void
    {
        if (!$ipn->isPaid()) {
            error_log('Transaction status changed: ' . $ipn->getStatus());
            return;
        }

        $orderId = (int) $ipn->getControl();
        if (!$orderId) {
            error_log('Order id is null');
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            error_log('Order #' . $orderId . ' not found');
            throw new \RuntimeException('Order not found: ' . $orderId);
        }

        // Idempotency
        if (in_array($order->get_status(), ['processing', 'completed'], true)) {
            error_log('Order #' . $orderId . ' already processed');
            return;
        }

        $transactionId = $ipn->getTransactionId();
        if ($transactionId === '') {
            error_log('Order #' . $orderId . ' cannot be processed');
            throw new \RuntimeException('Missing transaction id');
        }

        $orderTotal = (float) $order->get_total();
        $paidAmount = (float) ($ipn->data['amount']['final_value'] ?? $ipn->getAmount());
        $paidCurrency = (string) ($ipn->data['amount']['final_currency'] ?? $ipn->getCurrency());

        // Amount must not be lower than expected
        if (!AmountVerifier::isAmountSufficient($orderTotal, $paidAmount)) {
            $order->add_order_note(sprintf(
                'SimPay: Invalid payment amount. Expected %s %s, got %s %s.',
                $orderTotal,
                $order->get_currency(),
                $paidAmount,
                $paidCurrency
            ));
            $order->save();

            throw new \RuntimeException('Invalid payment amount');
        }

        // Save transaction id
        $order->update_meta_data('_simpay_transaction_id', $transactionId);

        // Mark as paid
        $order->payment_complete($transactionId);
        $order->add_order_note(sprintf(
            'SimPay: Payment completed. Amount: %s %s, Channel: %s, Transaction ID: %s',
            $paidAmount,
            $paidCurrency,
            (string) ($ipn->data['payment']['channel'] ?? 'unknown'),
            $transactionId
        ));

        $order->save();
    }

    /**
     * Handle "transaction_refund:status_changed" event.
     */
    public function handleRefundStatusChanged(IpnPayload $ipn): void
    {
        if ($ipn->getStatus() !== PaymentStatus::REFUND_COMPLETED) {
            return;
        }

        $transactionId = $ipn->getTransactionId();
        if ($transactionId === '') {
            return;
        }

        $simpayRefundId = (string) ($ipn->data['id'] ?? '');
        if ($simpayRefundId === '') {
            return;
        }

        $refundAmount   = $ipn->getAmount();
        $refundCurrency = $ipn->getCurrency();

        if ($refundAmount <= 0) {
            return;
        }

        // Find order by SimPay transaction id stored during payment
        $orders = wc_get_orders([
            'meta_key'   => '_simpay_transaction_id',
            'meta_value' => $transactionId,
            'limit'      => 1,
        ]);

        if (empty($orders)) {
            return;
        }

        $order = $orders[0];

        $existingRefund = $this->refunds->findRefundByRemoteId($order, $simpayRefundId);

        if ($existingRefund instanceof \WC_Order_Refund) {
            $currentStatus = (string) $existingRefund->get_meta('_simpay_refund_status');
            if ($currentStatus !== PaymentStatus::REFUND_COMPLETED) {
                $this->refunds->syncRefundMeta(
                    $existingRefund,
                    $simpayRefundId,
                    $refundAmount,
                    $refundCurrency ?: $order->get_currency(),
                    PaymentStatus::REFUND_COMPLETED,
                    (string) $existingRefund->get_meta('_simpay_refund_origin') ?: 'woocommerce'
                );

                $order->add_order_note(sprintf(
                    'SimPay: Refund confirmed. Amount: %s %s, SimPay refund ID: %s, Woo refund ID: %s',
                    $refundAmount,
                    $refundCurrency ?: $order->get_currency(),
                    $simpayRefundId,
                    $existingRefund->get_id()
                ));
                $order->save();
            }

            $this->refunds->removePendingRefund($order, $simpayRefundId);
            return;
        }

        $refund = wc_create_refund([
            'amount'   => $refundAmount,
            'reason'   => sprintf('SimPay - automatic refund (ID: %s)', $simpayRefundId),
            'order_id' => $order->get_id(),
        ]);

        if (is_wp_error($refund)) {
            $order->add_order_note(sprintf(
                'SimPay: Failed to create WooCommerce refund. Amount: %s %s, SimPay refund ID: %s, Error: %s',
                $refundAmount,
                $refundCurrency ?: $order->get_currency(),
                $simpayRefundId,
                $refund->get_error_message()
            ));
            $order->save();
            return;
        }

        $this->refunds->syncRefundMeta(
            $refund,
            $simpayRefundId,
            $refundAmount,
            $refundCurrency ?: $order->get_currency(),
            PaymentStatus::REFUND_COMPLETED,
            'simpay'
        );
        $this->refunds->removePendingRefund($order, $simpayRefundId);

        $order->add_order_note(sprintf(
            'SimPay: Refund completed. Amount: %s %s, SimPay refund ID: %s, Woo refund ID: %s',
            $refundAmount,
            $refundCurrency ?: $order->get_currency(),
            $simpayRefundId,
            $refund->get_id()
        ));

        $order->save();
    }

    /**
     * Handle "ipn:test" event.
     */
    public function handleTestNotification(IpnPayload $ipn): void
    {
        error_log('SimPay IPN v2 Test notification received for service: ' . ($ipn->data['service_id'] ?? 'unknown'));
    }
}
