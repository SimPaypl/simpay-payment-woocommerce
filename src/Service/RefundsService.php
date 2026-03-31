<?php

namespace SimPay\WooCommerce\Service;

use SimPay\WooCommerce\Api\SimPayApiClient;

class RefundsService
{
    private const ORDER_PENDING_REFUNDS_META = '_simpay_pending_refunds';
    private const REFUND_ID_META = '_simpay_refund_id';
    private const REFUND_STATUS_META = '_simpay_refund_status';
    private const REFUND_AMOUNT_META = '_simpay_refund_amount';
    private const REFUND_CURRENCY_META = '_simpay_refund_currency';
    private const REFUND_ORIGIN_META = '_simpay_refund_origin';

    private SimPayApiClient $api;

    public function __construct(SimPayApiClient $api)
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
        } catch (\RuntimeException $e) {
            if ($this->isPendingRefundConflict($e)) {
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

        $this->storePendingRefund($order, [
            'id' => $simpayRefundId,
            'amount' => $requestedAmount,
            'currency' => (string) $order->get_currency(),
            'reason' => $reason,
            'created_at' => gmdate('c'),
        ]);

        if ($localRefund instanceof \WC_Order_Refund) {
            $this->attachPendingRefundToWooRefund($order, $localRefund, $requestedAmount);
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
            if ((string) $refund->get_meta(self::REFUND_STATUS_META) !== 'requested') {
                continue;
            }

            throw new \RuntimeException(
                __('SimPay refund error: Previous refund request is still waiting for confirmation. Please wait a moment and try again.', 'simpay')
            );
        }
    }

    private function isPendingRefundConflict(\RuntimeException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 409')
            && str_contains($message, 'A refund request for this transaction already exists');
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

            $this->syncRefundMeta(
                $refund,
                (string) ($pendingRefund['id'] ?? ''),
                $pendingAmount,
                (string) ($pendingRefund['currency'] ?? $order->get_currency()),
                'requested',
                'woocommerce'
            );

            unset($pendingRefunds[$index]);
            $this->savePendingRefunds($order, $pendingRefunds);
            return;
        }
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

