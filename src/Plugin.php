<?php

namespace SimPay\WooCommerce;

use SimPay\WooCommerce\Config\Gateways;
use SimPay\WooCommerce\Factory\SimPayFactory;
use SimPay\WooCommerce\IPN\IPNController;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;
use SimPay\WooCommerce\Blocks\SimPayBlocksSupport;
use SimPay\WooCommerce\Update\PluginUpdateChecker;
use SimPay\WooCommerce\Blik\BlikAjaxController;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

class Plugin
{
    public static function run(): void
    {
        add_action('init', [self::class, 'load_textdomain']);
        add_action('admin_init', [self::class, 'register_update_checker']);

        // WooCommerce missing -> do nothing
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [self::class, 'notice_missing_woocommerce']);
            return;
        }

        // Classic checkout gateway
        add_filter('woocommerce_payment_gateways', [self::class, 'register_gateways']);

        // Webhook/IPN endpoint
        add_action('woocommerce_api_simpay', function () {
            (new IPNController())->handle();
        });

        // Bind remote refund created in SimPay to the local WooCommerce refund
        add_action('woocommerce_refund_created', function (int $refundId) {
            SimPayFactory::refunds()->bindPendingRefundToWooRefund($refundId);
        }, 10, 1);

        // Blocks checkout integration
        add_action('woocommerce_blocks_loaded', [self::class, 'register_blocks']);

        // BLIK Level 0 AJAX endpoints
        BlikAjaxController::register();

        // BLIK Level 0 frontend scripts
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_blik_scripts']);

        // Display settings page in admin
        if (is_admin()) {
            new SimPayGlobalSettings();
        }
    }

    public static function register_update_checker(): void
    {
        (new PluginUpdateChecker(WC_SIMPAY_VERSION))->register();
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

    public static function enqueue_blik_scripts(): void
    {
        if (!function_exists('is_checkout') || (!is_checkout() && !is_wc_endpoint_url('order-pay') && !is_wc_endpoint_url('order-received'))) {
            return;
        }

        if (!WC()->payment_gateways()) {
            return;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $blikGateway = $gateways['simpay_blik'] ?? null;

        if (!$blikGateway instanceof \SimPay\WooCommerce\Gateways\Blik || !$blikGateway->is_blik_level0_enabled()) {
            return;
        }

        wp_enqueue_style(
            'simpay-blik',
            WC_SIMPAY_URL . 'assets/css/simpay-blik.css',
            [],
            WC_SIMPAY_VERSION
        );

        wp_enqueue_script(
            'simpay-blik-widget',
            WC_SIMPAY_URL . 'assets/js/simpay-blik-widget.js',
            [],
            WC_SIMPAY_VERSION,
            true
        );

        wp_enqueue_script(
            'simpay-blik',
            WC_SIMPAY_URL . 'assets/js/simpay-blik.js',
            ['jquery', 'simpay-blik-widget'],
            WC_SIMPAY_VERSION,
            true
        );

        $script_data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('simpay_blik_nonce'),
            'level0Enabled' => true,
            'orderId' => null,
            'blikPending' => false,
            'i18n' => [
                'processing' => __('Processing your data, please wait.', 'simpay'),
                'confirmInApp' => __('Confirm the payment in your banking app.', 'simpay'),
                'waitingForConfirmation' => __('Waiting for confirmation in your banking app…', 'simpay'),
                'success' => __('Payment confirmed. Redirecting to order confirmation…', 'simpay'),
                'genericError' => __('Payment failed. Try again.', 'simpay'),
                'timeout' => __('Payment failed - not confirmed on time in the banking application. Try again.', 'simpay'),
                'tryAgain' => __('Try again', 'simpay'),
            ],
        ];

        // If on order-received page, check for pending BLIK
        if (is_wc_endpoint_url('order-received')) {
            global $wp;
            $order_id = isset($wp->query_vars['order-received']) ? (int) $wp->query_vars['order-received'] : 0;
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order instanceof \WC_Order && (string) $order->get_meta('_simpay_blik_status') === 'awaiting_confirmation') {
                    $script_data['orderId'] = $order_id;
                    $script_data['blikPending'] = true;
                }
            }
        }

        wp_localize_script('simpay-blik-widget', 'simpayBlikConfig', $script_data);
    }
}
