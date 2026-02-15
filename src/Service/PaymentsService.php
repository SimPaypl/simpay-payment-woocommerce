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
    public function createTransaction(\WC_Order $order, string $gatewayId, string $returnUrl): array
    {
        $gatewayIdChannel = Gateways::get($gatewayId, 'api');
        $payload = $this->buildPayload($order, $gatewayIdChannel, $returnUrl);
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

    private function buildPayload(\WC_Order $order, $gatewayIdChannel, string $returnUrl): array
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

        // Add direct channel if configured for this gateway
        if (!empty($gatewayIdChannel)) {
            $payload['directChannel'] = $gatewayIdChannel;
        }

        return $payload;
    }
}
