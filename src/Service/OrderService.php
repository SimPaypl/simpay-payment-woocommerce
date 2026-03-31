<?php

namespace SimPay\WooCommerce\Service;

class OrderService
{
    private RefundsService $refunds;

    public function __construct(RefundsService $refunds)
    {
        $this->refunds = $refunds;
    }

    /**
     * Handle "transaction:status_changed" event.
     * Business logic for marking the order as paid belongs here.
     */
    public function handleTransactionStatusChanged(array $data): void
    {
        if (($data['status'] ?? '') !== 'transaction_paid') {
            error_log('Transaction status changed: ' . print_r($data['status'], true));
            return;
        }

        $orderId = isset($data['control']) ? (int) $data['control'] : 0;
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

        $transactionId = (string) ($data['id'] ?? '');
        if ($transactionId === '') {
            error_log('Order #' . $orderId . ' cannot be processed');
            throw new \RuntimeException('Missing transaction id');
        }

        $paidAmount   = (float) ($data['amount']['final_value'] ?? 0);
        $orderTotal   = (float) $order->get_total();

        // Amount must not be lower than expected
        if ($paidAmount < $orderTotal) {
            $order->add_order_note(sprintf(
                'SimPay: Invalid payment amount. Expected %s %s, got %s %s.',
                $orderTotal,
                $order->get_currency(),
                $paidAmount,
                $data['amount']['final_currency'] ?? ''
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
            $data['amount']['final_currency'],
            (string) ($data['payment']['channel'] ?? 'unknown'),
            $transactionId
        ));

        $order->save();
    }

    /**
     * Handle "transaction_refund:status_changed" event.
     * Business logic for creating WooCommerce refunds belongs here.
     */
    public function handleRefundStatusChanged(array $data): void
    {
        if (($data['status'] ?? '') !== 'refund_completed') {
            return;
        }

        $transactionId = (string) ($data['transaction']['id'] ?? '');
        if ($transactionId === '') {
            return;
        }

        $simpayRefundId = (string) ($data['id'] ?? '');
        if ($simpayRefundId === '') {
            return;
        }

        $refundAmount   = (float) ($data['amount']['value'] ?? 0);
        $refundCurrency = (string) ($data['amount']['currency'] ?? '');

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
            if ($currentStatus !== 'refund_completed') {
                $this->refunds->syncRefundMeta(
                    $existingRefund,
                    $simpayRefundId,
                    $refundAmount,
                    $refundCurrency ?: $order->get_currency(),
                    'refund_completed',
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
            'refund_completed',
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
     * Keep it as no-op or log (prefer WC_Logger).
     */
    public function handleTestNotification(array $data): void
    {
        error_log('SimPay IPN v2 Test notification received for service: ' . $data['service_id']);
    }
}
