<?php

namespace SimPay\WooCommerce\IPN;

use SimPay\SDK\IpnPayload;
use SimPay\WooCommerce\Service\OrderService;
use SimPay\WooCommerce\Blik\BlikPaymentHandler;

class IPNEventDispatcher
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Route IPN events to services using SDK's IpnPayload DTO.
     *
     * @throws \RuntimeException for unknown event types
     */
    public function dispatch(IpnPayload $ipn): void
    {
        if ($ipn->isTransactionEvent()) {
            $this->orderService->handleTransactionStatusChanged($ipn);
            return;
        }

        if ($ipn->isRefundEvent()) {
            $this->orderService->handleRefundStatusChanged($ipn);
            return;
        }

        if ($ipn->isBlikCodeStatusEvent()) {
            BlikPaymentHandler::handleBlikCodeStatus($ipn);
            return;
        }

        if ($ipn->isBlikAliasEvent()) {
            BlikPaymentHandler::handleBlikAliasStatus($ipn);
            return;
        }

        if ($ipn->type === 'ipn:test') {
            $this->orderService->handleTestNotification($ipn);
            return;
        }

        throw new \RuntimeException('Unknown event type: ' . $ipn->type);
    }
}
