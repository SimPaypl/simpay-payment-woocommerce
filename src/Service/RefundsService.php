<?php

namespace SimPay\WooCommerce\Service;

use SimPay\SDK\PaymentStatus;
use SimPay\SDK\SimPayClient;
use SimPay\SDK\Exception\ApiException;

class RefundsService
{
    private const ORDER_PENDING_REFUNDS_META = '_simpay_pending_refunds';
    private const REFUND_ID_META = '_simpay_refund_id';
    private const REFUND_STATUS_META = '_simpay_refund_status';
    private const REFUND_AMOUNT_META = '_simpay_refund_amount';
    private const REFUND_CURRENCY_META = '_simpay_refund_currency';
    private const REFUND_ORIGIN_META = '_simpay_refund_origin';
    private const REFUND_ORDER_STATUS_META = '_simpay_refund_order_status';
    private const ORDER_FAILED_REFUNDS_META = '_simpay_failed_refunds';

    private const STATUS_REQUESTED = 'requested';
    private const STATUS_FAILED = 'failed';

    private SimPayClient $api;

    public function __construct(SimPayClient $api)
    {
        $this->api = $api;
    }

    public function requestRefund(\WC_Order $order, ?float $amount = null, string $reason = ''): array
    {
        $transactionId = (string) $order->get_meta('_simpay_transaction_id');
        if ($transactionId === '') {
            throw new \RuntimeException(__('SimPay refund error: Missing transaction ID.', 'simpay'));
        }

        $localRefund = $this->findUnlinkedWooRefund($order, $amount, $reason);
        $remainingAmount = $this->normalizeAmount($order->get_remaining_refund_amount());

        if ($localRefund instanceof \WC_Order_Refund) {
            $remainingAmount = $this->normalizeAmount(
                $remainingAmount + abs((float) $localRefund->get_amount())
            );
        }

        if ($remainingAmount <= 0.0) {
            throw new \RuntimeException(__('SimPay refund error: Nothing left to refund.', 'simpay'));
        }

        $requestedAmount = $amount !== null
            ? $this->normalizeAmount($amount)
            : $remainingAmount;

        if ($requestedAmount <= 0.0) {
            throw new \RuntimeException(__('SimPay refund error: Refund amount must be greater than zero.', 'simpay'));
        }

        if ($requestedAmount > $remainingAmount) {
            throw new \RuntimeException(__('SimPay refund error: Refund amount exceeds the remaining refundable amount.', 'simpay'));
        }

        $this->ensureNoPendingRefundRequest($order);

        try {
            $response = $this->api->createRefund(
                $transactionId,
                $amount !== null ? $requestedAmount : null
            );
        } catch (ApiException $e) {
            if ($e->getHttpStatusCode() === 409) {
                throw new \RuntimeException(
                    __('SimPay refund error: Previous refund request is still waiting for confirmation. Please wait a moment and try again.', 'simpay')
                );
            }

            throw $e;
        }

        $simpayRefundId = (string) ($response['data']['refund_id'] ?? '');

        if ($simpayRefundId === '') {
            throw new \RuntimeException(__('SimPay refund error: Missing refund ID in API response.', 'simpay'));
        }

        $pendingRefund = [
            'id' => $simpayRefundId,
            'amount' => $requestedAmount,
            'currency' => (string) $order->get_currency(),
            'reason' => $reason,
            'created_at' => gmdate('c'),
            // Status before WooCommerce applies the refund (e.g. switches to "refunded"),
            // needed to restore the order if SimPay later reports the refund as failed.
            'order_status' => (string) $order->get_status(),
        ];

        if ($localRefund instanceof \WC_Order_Refund) {
            // Link immediately, so an early refund_failed IPN can find the Woo refund by its SimPay ID.
            $this->linkWooRefund($order, $localRefund, $pendingRefund);
        } else {
            $this->storePendingRefund($order, $pendingRefund);
        }

        return [
            'refund_id' => $simpayRefundId,
            'amount' => $requestedAmount,
            'currency' => (string) $order->get_currency(),
            'partial' => $requestedAmount !== $remainingAmount,
        ];
    }

    public function bindPendingRefundToWooRefund(int $refundId): void
    {
        $refund = wc_get_order($refundId);
        if (!$refund instanceof \WC_Order_Refund) {
            return;
        }

        $order = wc_get_order($refund->get_parent_id());
        if (!$order instanceof \WC_Order) {
            return;
        }

        $pendingRefunds = $this->getPendingRefunds($order);
        if ($pendingRefunds === []) {
            return;
        }

        $refundAmount = $this->normalizeAmount((float) abs($refund->get_amount()));

        $this->attachPendingRefundToWooRefund($order, $refund, $refundAmount);
    }

    public function rollbackUnlinkedWooRefund(\WC_Order $order, ?float $amount = null, string $reason = ''): void
    {
        $refund = $this->findUnlinkedWooRefund($order, $amount, $reason);
        if (!$refund instanceof \WC_Order_Refund) {
            return;
        }

        $refund->delete(true);
    }

    public function findRefundByRemoteId(\WC_Order $order, string $simpayRefundId): ?\WC_Order_Refund
    {
        foreach ($order->get_refunds() as $refund) {
            if ((string) $refund->get_meta(self::REFUND_ID_META) === $simpayRefundId) {
                return $refund;
            }
        }

        return null;
    }

    public function syncRefundMeta(
        \WC_Order_Refund $refund,
        string $simpayRefundId,
        float $amount,
        string $currency,
        string $status,
        string $origin
    ): void {
        if ($simpayRefundId !== '') {
            $refund->update_meta_data(self::REFUND_ID_META, $simpayRefundId);
        }

        $refund->update_meta_data(self::REFUND_STATUS_META, $status);
        $refund->update_meta_data(self::REFUND_AMOUNT_META, $this->normalizeAmount($amount));
        $refund->update_meta_data(self::REFUND_CURRENCY_META, $currency);
        $refund->update_meta_data(self::REFUND_ORIGIN_META, $origin);
        $refund->save();
    }

    public function removePendingRefund(\WC_Order $order, string $simpayRefundId): void
    {
        $pendingRefunds = $this->getPendingRefunds($order);
        if ($pendingRefunds === []) {
            return;
        }

        $pendingRefunds = array_values(array_filter(
            $pendingRefunds,
            static fn (array $pendingRefund): bool => (string) ($pendingRefund['id'] ?? '') !== $simpayRefundId
        ));

        $this->savePendingRefunds($order, $pendingRefunds);
    }

    private function storePendingRefund(\WC_Order $order, array $refundData): void
    {
        $pendingRefunds = $this->getPendingRefunds($order);
        $pendingRefunds[] = $refundData;
        $this->savePendingRefunds($order, $pendingRefunds);
    }

    private function getPendingRefunds(\WC_Order $order): array
    {
        $pendingRefunds = $order->get_meta(self::ORDER_PENDING_REFUNDS_META, true);

        return is_array($pendingRefunds) ? array_values($pendingRefunds) : [];
    }

    private function savePendingRefunds(\WC_Order $order, array $pendingRefunds): void
    {
        if ($pendingRefunds === []) {
            $order->delete_meta_data(self::ORDER_PENDING_REFUNDS_META);
        } else {
            $order->update_meta_data(self::ORDER_PENDING_REFUNDS_META, array_values($pendingRefunds));
        }

        $order->save();
    }

    private function normalizeAmount(float $amount): float
    {
        return (float) wc_format_decimal($amount, wc_get_price_decimals());
    }

    private function ensureNoPendingRefundRequest(\WC_Order $order): void
    {
        if ($this->getPendingRefunds($order) !== []) {
            throw new \RuntimeException(
                __('SimPay refund error: Previous refund request is still waiting for confirmation. Please wait a moment and try again.', 'simpay')
            );
        }

        foreach ($order->get_refunds() as $refund) {
            if ((string) $refund->get_meta(self::REFUND_STATUS_META) !== self::STATUS_REQUESTED) {
                continue;
            }

            throw new \RuntimeException(
                __('SimPay refund error: Previous refund request is still waiting for confirmation. Please wait a moment and try again.', 'simpay')
            );
        }
    }


    private function attachPendingRefundToWooRefund(\WC_Order $order, \WC_Order_Refund $refund, float $refundAmount): void
    {
        $pendingRefunds = $this->getPendingRefunds($order);
        if ($pendingRefunds === []) {
            return;
        }

        foreach ($pendingRefunds as $index => $pendingRefund) {
            $pendingAmount = $this->normalizeAmount((float) ($pendingRefund['amount'] ?? 0));
            if ($pendingAmount !== $refundAmount) {
                continue;
            }

            $this->linkWooRefund($order, $refund, $pendingRefund);

            unset($pendingRefunds[$index]);
            $this->savePendingRefunds($order, $pendingRefunds);
            return;
        }
    }

    private function linkWooRefund(\WC_Order $order, \WC_Order_Refund $refund, array $pendingRefund): void
    {
        $orderStatus = (string) ($pendingRefund['order_status'] ?? '');
        if ($orderStatus !== '') {
            $refund->update_meta_data(self::REFUND_ORDER_STATUS_META, $orderStatus);
        }

        $this->syncRefundMeta(
            $refund,
            (string) ($pendingRefund['id'] ?? ''),
            $this->normalizeAmount((float) ($pendingRefund['amount'] ?? 0)),
            (string) ($pendingRefund['currency'] ?? $order->get_currency()),
            self::STATUS_REQUESTED,
            'woocommerce'
        );
    }

    /**
     * Reconcile a refund that SimPay reported as terminally failed (refund_failed IPN).
     *
     * Idempotent: duplicates are recognised by the per-order list of failed SimPay refund IDs,
     * which is written only as the very last step, so a retry after a partial failure finishes the job.
     */
    public function handleRemoteRefundFailure(\WC_Order $order, string $simpayRefundId): void
    {
        if ($this->isRefundFailed($order, $simpayRefundId)) {
            return;
        }

        $wooRefund = $this->findRefundByRemoteId($order, $simpayRefundId);
        $pendingRefund = $this->findPendingRefund($order, $simpayRefundId);

        if ($wooRefund instanceof \WC_Order_Refund) {
            if ((string) $wooRefund->get_meta(self::REFUND_STATUS_META) === PaymentStatus::REFUND_COMPLETED) {
                // Completed is terminal; a late/contradictory failure must not undo it.
                error_log(sprintf(
                    'SimPay: Ignoring refund_failed for already completed refund %s (order #%d).',
                    $simpayRefundId,
                    $order->get_id()
                ));
                return;
            }

            $this->revertFailedWooRefund($order, $wooRefund, $simpayRefundId);
        } elseif ($pendingRefund !== null) {
            $amount = $this->normalizeAmount((float) ($pendingRefund['amount'] ?? 0));
            $currency = (string) ($pendingRefund['currency'] ?? $order->get_currency());

            $note = sprintf(
                'SimPay: Refund failed. Amount: %s %s, SimPay refund ID: %s. No funds were returned; the refund can be requested again.',
                $amount,
                $currency,
                $simpayRefundId
            );

            // The request was never bound to a WooCommerce refund. We do not guess which refund
            // it was (amount matching is ambiguous), so the operator is asked to verify it.
            if ($this->findUnlinkedWooRefund($order, $amount) instanceof \WC_Order_Refund) {
                $note .= ' ' . sprintf(
                    'Warning: the order has a WooCommerce refund of %s %s not linked to SimPay. If it belongs to this request, delete it manually.',
                    $amount,
                    $currency
                );
            }

            $order->add_order_note($note);
        } else {
            error_log(sprintf(
                'SimPay: Ignoring refund_failed for unknown refund %s (order #%d).',
                $simpayRefundId,
                $order->get_id()
            ));
            return;
        }

        $this->removePendingRefund($order, $simpayRefundId);
        $this->markRefundFailed($order, $simpayRefundId);
    }

    public function isRefundFailed(\WC_Order $order, string $simpayRefundId): bool
    {
        return in_array($simpayRefundId, $this->getFailedRefundIds($order), true);
    }

    /**
     * Undo the local effects of a WooCommerce refund whose payout failed in SimPay:
     * the refund (with its tax and line item adjustments) is removed, so the refundable amount is
     * correct again, and the order status changed by a full refund is restored.
     * Stock is not touched automatically; the operator is warned instead.
     */
    private function revertFailedWooRefund(\WC_Order $order, \WC_Order_Refund $refund, string $simpayRefundId): void
    {
        $wooRefundId = $refund->get_id();
        $amount = $this->normalizeAmount((float) abs((float) $refund->get_amount()));
        $currency = (string) ($refund->get_meta(self::REFUND_CURRENCY_META) ?: $order->get_currency());
        $hadItems = $this->refundHasItems($refund);

        // Step 1: stop treating it as pending, so a crash below does not leave the order blocked.
        if ((string) $refund->get_meta(self::REFUND_STATUS_META) !== self::STATUS_FAILED) {
            $refund->update_meta_data(self::REFUND_STATUS_META, self::STATUS_FAILED);
            $refund->save();
        }

        // Step 2: restore the status before the refund disappears (we still know its context).
        $restoredStatus = '';
        if ($order->get_status() === 'refunded' && $amount > 0.0) {
            $restoredStatus = (string) $refund->get_meta(self::REFUND_ORDER_STATUS_META);
            if ($restoredStatus === '' || $restoredStatus === 'refunded') {
                $restoredStatus = $order->needs_processing() ? 'processing' : 'completed';
            }

            $this->updateStatusSilently(
                $order,
                $restoredStatus,
                sprintf('SimPay: Refund %s failed, restoring order status.', $simpayRefundId)
            );
        }

        // Step 3: remove the refund, like WooCommerce does when deleting a refund in the admin.
        $refund->delete(true);
        do_action('woocommerce_refund_deleted', $wooRefundId, $order->get_id());

        if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
            \WC_Cache_Helper::invalidate_cache_group('orders');
        }

        $note = sprintf(
            'SimPay: Refund failed. Amount: %s %s, SimPay refund ID: %s. No funds were returned; WooCommerce refund #%d was removed and the amount can be refunded again.',
            $amount,
            $currency,
            $simpayRefundId,
            $wooRefundId
        );

        if ($restoredStatus !== '') {
            $note .= ' ' . sprintf('Order status restored to "%s".', wc_get_order_status_name($restoredStatus));
        }

        if ($hadItems) {
            $note .= ' Warning: the refund contained line items. If stock was restocked for it, adjust stock manually.';
        }

        $order->add_order_note($note);
    }

    /**
     * Change the order status without re-sending "processing"/"completed" emails to the customer,
     * since from their perspective the order already went through those states.
     */
    private function updateStatusSilently(\WC_Order $order, string $status, string $note): void
    {
        $filters = [
            'woocommerce_email_enabled_customer_processing_order',
            'woocommerce_email_enabled_customer_completed_order',
        ];

        foreach ($filters as $filter) {
            add_filter($filter, '__return_false', PHP_INT_MAX);
        }

        try {
            $order->update_status($status, $note);
        } finally {
            foreach ($filters as $filter) {
                remove_filter($filter, '__return_false', PHP_INT_MAX);
            }
        }
    }

    private function refundHasItems(\WC_Order_Refund $refund): bool
    {
        foreach ($refund->get_items() as $item) {
            if (abs((float) $item->get_quantity()) > 0) {
                return true;
            }
        }

        return false;
    }

    private function findPendingRefund(\WC_Order $order, string $simpayRefundId): ?array
    {
        foreach ($this->getPendingRefunds($order) as $pendingRefund) {
            if ((string) ($pendingRefund['id'] ?? '') === $simpayRefundId) {
                return $pendingRefund;
            }
        }

        return null;
    }

    private function getFailedRefundIds(\WC_Order $order): array
    {
        $ids = $order->get_meta(self::ORDER_FAILED_REFUNDS_META, true);

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }

    private function markRefundFailed(\WC_Order $order, string $simpayRefundId): void
    {
        $ids = $this->getFailedRefundIds($order);
        if (!in_array($simpayRefundId, $ids, true)) {
            $ids[] = $simpayRefundId;
        }

        $order->update_meta_data(self::ORDER_FAILED_REFUNDS_META, $ids);
        $order->save();
    }

    private function findUnlinkedWooRefund(\WC_Order $order, ?float $amount = null, string $reason = ''): ?\WC_Order_Refund
    {
        $targetAmount = $amount !== null ? $this->normalizeAmount($amount) : null;

        $refunds = $order->get_refunds();
        usort($refunds, static function (\WC_Order_Refund $left, \WC_Order_Refund $right): int {
            return $right->get_id() <=> $left->get_id();
        });

        foreach ($refunds as $refund) {
            if ((string) $refund->get_meta(self::REFUND_ID_META) !== '') {
                continue;
            }

            if ((string) $refund->get_meta(self::REFUND_STATUS_META) !== '') {
                continue;
            }

            $refundAmount = $this->normalizeAmount((float) abs($refund->get_amount()));
            if ($targetAmount !== null && $refundAmount !== $targetAmount) {
                continue;
            }

            if ($reason !== '' && (string) $refund->get_reason() !== $reason) {
                continue;
            }

            return $refund;
        }

        return null;
    }
}

