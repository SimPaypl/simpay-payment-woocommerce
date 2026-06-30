<?php

namespace SimPay\WooCommerce\Blik;

/**
 * AJAX endpoints for BLIK Level 0 payment flow:
 * - simpay_blik_status: Poll payment status
 */
final class BlikAjaxController
{
    public static function register(): void
    {
        add_action('wp_ajax_simpay_blik_status', [self::class, 'handleStatus']);
        add_action('wp_ajax_nopriv_simpay_blik_status', [self::class, 'handleStatus']);
    }

    public static function handleStatus(): void
    {
        check_ajax_referer('simpay_blik_nonce', 'nonce');

        $orderId = (int) ($_POST['order_id'] ?? $_GET['order_id'] ?? 0);

        if ($orderId <= 0) {
            wp_send_json_error(['message' => 'Invalid order'], 400);
        }

        $status = BlikPaymentHandler::getStatus($orderId);

        wp_send_json_success($status);
    }
}
