<?php

namespace SimPay\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use SimPay\WooCommerce\Service\CommissionService;

class SimPayBlocksSupport extends AbstractPaymentMethodType
{
    protected $name = 'simpay_payment_gateway';

    protected $settings = [];

    public function initialize()
    {
        $this->settings = get_option('woocommerce_simpay_payment_gateway_settings', []);
    }

    public function is_active()
    {
        return isset($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'simpay_woocommerce_payment_blocks_integration',
            plugins_url('../assets/js/simpay_woocommerce_payment_blocks_integration.js', __FILE__),
            ['wc-blocks-registry', 'wc-blocks-checkout', 'wc-settings', 'wp-element', 'wp-i18n'],
            '1.0.5',
            true
        );

        return ['simpay_woocommerce_payment_blocks_integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->settings['title'] ?? 'SimPay',
            'description' => $this->settings['description'] ?? '',
            'supports' => [],
            // The channel is chosen on the gateway page, so only the generic fee notice applies here.
            'commissionNotice' => CommissionService::isPayerMode()
                ? __('A transaction fee will be added to the payment amount. The exact amount will be shown on the payment gateway.', 'simpay')
                : '',
        ];
    }
}