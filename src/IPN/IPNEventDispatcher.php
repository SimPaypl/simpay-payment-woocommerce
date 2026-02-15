<?php

namespace SimPay\WooCommerce\IPN;

use SimPay\WooCommerce\Service\OrderService;

class IPNEventDispatcher
{
    /** @var OrderService */
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Route IPN events to services.
     *
     * @throws \RuntimeException for unknown event types
     */
    public function dispatch(array $payload): void
    {
        $type = (string) ($payload['type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($type === '') {
            throw new \RuntimeException('Missing event type');
        }
        error_log('Test status changed event: ' . print_r($payload['type'], true));
        switch ($type) {
            case 'transaction:status_changed':
                $this->orderService->handleTransactionStatusChanged($data);
                return;

            case 'transaction_refund:status_changed':
                $this->orderService->handleRefundStatusChanged($data);
                return;

            case 'ipn:test':
                $this->orderService->handleTestNotification($data);
                return;


            default:
                throw new \RuntimeException('Unknown event type: ' . $type);
        }
    }
}
