<?php

namespace SimPay\WooCommerce\Service;

	use SimPay\SDK\SimPay;
use SimPay\SDK\TransactionBuilder;
use SimPay\WooCommerce\Config\Gateways;

class PaymentsService
{
    private SimPay $simpay;

    public function __construct(SimPay $simpay)
    {
        $this->simpay = $simpay;
    }

    public function createTransaction(\WC_Order $order, \WC_Customer $customer, string $gatewayId, string $returnUrl, ?string $directChannel = null): array
    {
        $gatewayIdChannel = $directChannel ?? Gateways::get($gatewayId, 'api');
        $payload = $this->buildPayload($order, $customer, $gatewayIdChannel, $returnUrl);
        $response = $this->simpay->client()->createTransaction($payload);
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

    private function buildPayload(\WC_Order $order, \WC_Customer $customer, ?string $gatewayIdChannel, string $returnUrl): array
    {
        $builder = TransactionBuilder::create()
            ->setAmount((float) $order->get_total(), $order->get_currency())
            ->setDescription(sprintf(__('Order #%d', 'simpay'), $order->get_order_number()))
            ->setControl((string) $order->get_id())
            ->setCustomer(
                trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                $order->get_billing_email(),
                $order->get_customer_ip_address()
            )
            ->setAntifraud(null, $order->get_customer_user_agent())
            ->setBilling(
                $order->get_billing_first_name(),
                $order->get_billing_last_name(),
                trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
                null,
                $order->get_billing_city(),
                $order->get_billing_postcode(),
                $order->get_billing_country(),
                $order->get_billing_company()
            )
            ->setShipping(
                $order->get_shipping_first_name(),
                $order->get_shipping_last_name(),
                trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
                null,
                $order->get_shipping_city(),
                $order->get_shipping_postcode(),
                $order->get_shipping_country(),
                $order->get_shipping_company()
            )
            ->setReturnUrls($returnUrl, $returnUrl, $returnUrl);

        if (!empty($gatewayIdChannel)) {
            $builder->setDirectChannel($gatewayIdChannel);
        }

        $this->applyContext($builder, $order, $customer);

        return $builder->toArray();
    }

    private function applyContext(TransactionBuilder $builder, \WC_Order $order, \WC_Customer $customer): void
    {
        if (!$customer->get_id()) {
            return;
        }

        $salesTotalCount  = (int) $customer->get_order_count();
        $salesTotalAmount = (float) $customer->get_total_spent();
        $salesAvgAmount   = $salesTotalCount > 0 ? $salesTotalAmount / $salesTotalCount : 0.0;

        $builder->setContext(
            accountCreatedAt: $this->formatWcDate($customer->get_date_created()),
            salesTotalCount: $salesTotalCount,
            salesTotalAmount: $salesTotalAmount,
            salesAvgAmount: $salesAvgAmount,
            salesMaxAmount: (float) $order->get_total(),
            accountSetCurrency: substr((string) $order->get_currency(), 0, 3),
            hasPreviousPurchases: $salesTotalCount > 0
        );
    }

    private function formatWcDate($date): ?string
    {
        return $date ? gmdate('c', $date->getTimestamp()) : null;
    }
}
