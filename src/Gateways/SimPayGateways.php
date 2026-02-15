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
        $this->supports = ['products'];
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
        try {
            $payments = SimPayFactory::payments();
            $result = $payments->createTransaction($order, $this->id, $this->get_return_url($order));

            return [
                'result'   => 'success',
                'redirect' => $result['redirect_url'],
            ];
        } catch (\Throwable $e) {
            wc_add_notice('SimPay error: ' . $e->getMessage(), 'error');
            return ['result' => 'failure'];
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

