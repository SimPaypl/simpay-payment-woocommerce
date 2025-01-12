<?php
/*
 * Plugin Name:       SimPay.pl Płatności Online WooCommerce
 * Plugin URI:        https://simpay.pl
 * Description:       Wtyczka WooCommerce, która umożliwi Ci przyjmowanie płatności Online
 * Version:           1.0.0
 * Author:            Patryk Vizauer
 * Author URI:        https://github.com/PatryQHyper
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       simpay_woocommerce_payment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'simpay_woocommerce_payment_init' );

function simpay_woocommerce_payment_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'src/SimPay_Gateway.php';
	require_once plugin_dir_path( __FILE__ ) . 'src/SimPay_IPN_Handler.php';

	add_filter( 'woocommerce_payment_gateways', 'simpay_woocommerce_payment_gateways' );

	function simpay_woocommerce_payment_gateways( $methods ) {
		$methods[] = 'SimPay_Gateway';

		return $methods;
	}
}

add_action('enqueue_block_assets', 'simpay_woocommerce_payment_block_assets');

function simpay_woocommerce_payment_block_assets() {
	if (!function_exists('is_checkout') || !is_checkout()) {
		return;
	}

	wp_enqueue_script(
		'simpay_woocommerce_payment_blocks_integration',
		plugins_url('assets/js/simpay_woocommerce_payment_blocks_integration.js', __FILE__),
		array('wc-blocks-registry', 'wp-element', 'wp-i18n', 'wp-hooks'),
		'1.0.0',
		true
	);
}

//simpay_payment_gateway