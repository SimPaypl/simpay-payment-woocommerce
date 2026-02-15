<?php

namespace SimPay\WooCommerce\Service;

class OrderService
{
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

        // Idempotency: do not create duplicates for the same SimPay refund id
        foreach ($order->get_refunds() as $existingRefund) {
            if ((string) $existingRefund->get_meta('_simpay_refund_id') === $simpayRefundId) {
                $order->add_order_note(sprintf(
                    'SimPay: Zwrot środków już został przetworzony. Kwota: %s %s, ID zwrotu: %s',
                    $refundAmount,
                    $data['amount']['currency'],
                    $simpayRefundId
                ));
                return;
            }
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

        $refund->update_meta_data('_simpay_refund_id', $simpayRefundId);
        $refund->save();

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
