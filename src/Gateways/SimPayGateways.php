<?php

namespace SimPay\WooCommerce\Gateways;

use SimPay\WooCommerce\Config\Gateways;
use SimPay\WooCommerce\Factory\SimPayFactory;

abstract class SimPayGateways extends \WC_Payment_Gateway
{

    /**
     * Setup general properties for the gateway.
     *
     * @param string     $id
     */
    public function __construct(string $id)
    {
        $this->id = $id;

        $this->init_properties();
        $this->init_form_fields();
        $this->init_settings();

        $this->icon = WC_SIMPAY_URL . '/assets/img/simpay.svg';

        $this->title = $this->get_option('title', 'SimPay');
        $this->description = $this->get_option('description', __('Pay with SimPay payment gateway.', 'simpay'));

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
    }

    public function init_properties(): void
    {
        $this->method_title = Gateways::get($this->id, 'name');
        $this->method_description = __('SimPay payment gateway for WooCommerce.', 'simpay');
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'info' => [
                'type' => 'title',
                'description' => sprintf(
                    __('Configure API credentials in <a href="%s">SimPay Global Settings</a>.', 'simpay'),
                    admin_url('admin.php?page=simpay-settings')
                ),
            ],
            'enabled' => array(
                'title' => __('Turn on / Turn off', 'simpay'),
                'description' => __('Turn on payment method', 'simpay'),
                'type' => 'checkbox',
                'default' => Gateways::get($this->id, 'default_enabled')
            ),
            'title' => array(
                'title' => __('Title of payment method', 'simpay'),
                'description' => __('This title will be available to the buyer when selecting payment methods', 'simpay'),
                'type' => 'text',
                'default' => Gateways::get($this->id, 'front_name')
            ),
            'description' => array(
                'title' => __('Description of payment method', 'simpay'),
                'description' => __('This description will be available to the buyer when selecting payment methods', 'simpay'),
                'type' => 'text',
                'default' => Gateways::get($this->id, 'default_description')
            ),
        );

        if (strtoupper(get_woocommerce_currency()) !== 'PLN') {
            $this->form_fields = array_merge(
                [
                    'notice' => [
                        'type' => 'title',
                        'description' => __('Note: This gateway is only visible for PLN currency.', 'simpay'),
                    ],
                ],
                $this->form_fields
            );

            $this->form_fields['enabled']['default'] = 'no';
            $this->form_fields['enabled']['custom_attributes'] = [
                'disabled' => 'disabled',
            ];
        }
    }

    public function process_admin_options()
    {
        parent::process_admin_options();

        if (strtoupper(get_woocommerce_currency()) !== 'PLN') {
            $this->update_option('enabled', 'no');
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof \WC_Order) {
            wc_add_notice(__('SimPay error: Order not found.', 'simpay'), 'error');

            return ['result' => 'failure'];
        }

        $customer = new \WC_Customer((int) $order->get_customer_id());
        try {
            $payments = SimPayFactory::payments();
            $result = $payments->createTransaction($order, $customer, $this->id, $this->get_return_url($order));

            return [
                'result'   => 'success',
                'redirect' => $result['redirect_url'],
            ];
        } catch (\Throwable $e) {
            wc_add_notice('SimPay error: ' . $e->getMessage(), 'error');
            return ['result' => 'failure'];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof \WC_Order) {
            return new \WP_Error('simpay_refund_error', __('SimPay refund error: Order not found.', 'simpay'));
        }

        try {
            $refund = SimPayFactory::refunds()->requestRefund(
                $order,
                $amount !== null ? (float) $amount : null,
                (string) $reason
            );

            $order->add_order_note(sprintf(
                'SimPay: Refund requested. Amount: %s %s, SimPay refund ID: %s',
                $refund['amount'],
                $refund['currency'],
                $refund['refund_id']
            ));
            $order->save();

            return true;
        } catch (\Throwable $e) {
            SimPayFactory::refunds()->rollbackUnlinkedWooRefund(
                $order,
                $amount !== null ? (float) $amount : null,
                (string) $reason
            );

            $order->add_order_note('SimPay refund error: ' . $e->getMessage());
            $order->save();

            return new \WP_Error('simpay_refund_error', $e->getMessage());
        }
    }

    public function is_available(): bool
    {
        if (strtoupper(get_woocommerce_currency()) !== 'PLN') {
            return false;
        }

        return parent::is_available();
    }

}

