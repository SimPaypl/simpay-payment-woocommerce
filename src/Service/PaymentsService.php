<?php

namespace SimPay\WooCommerce\Service;

use SimPay\WooCommerce\Api\SimPayApiClient;
use SimPay\WooCommerce\Config\Gateways;

class PaymentsService
{
    private  SimPayApiClient $api;
    public function __construct(SimPayApiClient $api) {
        $this->api = $api;
    }
    public function createTransaction(\WC_Order $order, \WC_Customer $customer, string $gatewayId, string $returnUrl): array
    {
        $gatewayIdChannel = Gateways::get($gatewayId, 'api');
        $payload = $this->buildPayload($order, $customer, $gatewayIdChannel, $returnUrl);
        $response = $this->api->createTransaction($payload);
        $redirect = $response['data']['redirectUrl'] ?? null;
        $txId     = $response['data']['transactionId'] ?? null;

        if (!$redirect || !$txId) {
            wc_add_notice('SimPay init error: ' . json_encode($response), 'error');

            return array(
                'result' => 'failure',
            );
        }
        $order->add_order_note('SimPay ID: ' . $txId, 1);
        $order->update_meta_data('_simpay_transaction_id', $txId);
        $order->update_status('pending', 'Awaiting SimPay payment');
        $order->save();

        return [
            'redirect_url'    => $redirect,
            'transaction_id'  => $txId,
        ];
    }

    private function buildPayload(\WC_Order $order, \WC_Customer $customer, $gatewayIdChannel, string $returnUrl): array
    {
        $payload = [
            'amount'      => (float) $order->get_total(),
            'currency'    => $order->get_currency(),
            'description' => sprintf(__('Order #%d', 'simpay'), $order->get_order_number()),
            'control'     => (string) $order->get_id(),
            'customer'    => [
                'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email' => $order->get_billing_email(),
                'ip'    => $order->get_customer_ip_address(),
            ],
            'antifraud' => [
                'useragent' => $order->get_customer_user_agent(),
            ],
            'billing' => [
                'name'       => $order->get_billing_first_name(),
                'surname'    => $order->get_billing_last_name(),
                'street'     => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
                'city'       => $order->get_billing_city(),
                'postalCode' => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'company'    => $order->get_billing_company(),
            ],
            'shipping' => [
                'name'       => $order->get_shipping_first_name(),
                'surname'    => $order->get_shipping_last_name(),
                'street'     => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
                'city'       => $order->get_shipping_city(),
                'postalCode' => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
                'company'    => $order->get_shipping_company(),
            ],
            'returns' => [
                'success' => $returnUrl,
                'failure' => $returnUrl,
            ],
        ];

        $context = $this->buildContext($order, $customer);

        if ($context !== null) {
            $payload['context'] = $context;
        }

        if (!empty($gatewayIdChannel)) {
            $payload['directChannel'] = $gatewayIdChannel;
        }

        return $payload;
    }

    private function buildContext(\WC_Order $order, \WC_Customer $customer): ?array
    {
        if (!$customer->get_id()) {
            return null;
        }

        $salesTotalCount = (int) $customer->get_order_count();
        $salesTotalAmount = (float) $customer->get_total_spent();
        $salesAvgAmount = $salesTotalCount > 0 ? $salesTotalAmount / $salesTotalCount : 0.0;
        $lastOrder = $customer->get_last_order();
        $lastLoginAt = $lastOrder ? $this->formatWcDate($lastOrder->get_date_created()) : null;

        return [
            'accountCreatedAt' => $this->formatWcDate($customer->get_date_created()),
            'salesTotalCount' => $salesTotalCount,
            'salesTotalAmount' => $salesTotalAmount,
            'salesAvgAmount' => (float) $salesAvgAmount,
            'salesMaxAmount' => (float) $order->get_total(),
            'refundsTotalAmount' => (float) $order->get_total_refunded(),
            'previousChargeback' => false,
            'accountSetCurrency' => substr((string) $order->get_currency(), 0, 3),
            'lastLoginAt' => $lastLoginAt,
            'hasPreviousPurchases' => $salesTotalCount > 0,
        ];
    }

    private function formatWcDate($date): ?string
    {
        return $date ? gmdate('c', $date->getTimestamp()) : null;
    }
}
