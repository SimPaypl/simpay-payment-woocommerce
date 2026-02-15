<?php

namespace SimPay\WooCommerce;

use SimPay\WooCommerce\Config\Gateways;
use SimPay\WooCommerce\IPN\IPNController;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;
use SimPay\WooCommerce\Blocks\SimPayBlocksSupport;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

class Plugin
{
    public static function run(): void
    {
        add_action('init', [self::class, 'load_textdomain']);

        // WooCommerce missing -> do nothing
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [self::class, 'notice_missing_woocommerce']);
            return;
        }

        // Classic checkout gateway
        add_filter('woocommerce_payment_gateways', [self::class, 'register_gateways']);

        // Webhook/IPN endpoint
        add_action('woocommerce_api_simpay_ipn', function () {
            (new IPNController())->handle();
        });

        // Blocks checkout integration
        add_action('woocommerce_blocks_loaded', [self::class, 'register_blocks']);

        // Display settings page in admin
        if (is_admin()) {
            new SimPayGlobalSettings();
        }
    }

    public static function register_gateways(array $methods): array
    {
        foreach (Gateways::all() as $gateway) {
            if (!empty($gateway['class'])) {
                $methods[] = $gateway['class'];
            }
        }
        return $methods;
    }

    public static function register_blocks(): void
    {
        if (!class_exists(AbstractPaymentMethodType::class)) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (PaymentMethodRegistry $registry) {
                $registry->register(new SimPayBlocksSupport());
            }
        );
    }

    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'simpay',
            false,
            dirname(plugin_basename(WC_SIMPAY_PATH . 'simpay-payment-woocommerce.php')) . '/languages/'
        );
    }

    public static function notice_missing_woocommerce(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('SimPay requires WooCommerce to be installed and active.', 'simpay');
        echo '</p></div>';
    }
}
