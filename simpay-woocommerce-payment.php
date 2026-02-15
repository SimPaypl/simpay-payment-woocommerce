<?php
/*
 * Plugin Name:       SimPay Online Payments for WooCommerce
 * Requires Plugins:  woocommerce
 * Plugin URI:        https://simpay.pl
 * GitHub Plugin URI: https://github.com/SimPaypl/simpay-payment-woocommerce
 * Description:       Accept fast and secure online payments with SimPay – BLIK, online transfers and instant payments. Easy integration and smooth checkout for your customers.
 * Version:           1.1.0
 * Author:            Payments Solution Sp. z o.o.
 * Author URI:        https://simpay.pl
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       simpay
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WC_SIMPAY_VERSION', '1.1.0' );
define( 'WC_SIMPAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_SIMPAY_URL', plugin_dir_url( __FILE__ ) );

require WC_SIMPAY_PATH . 'vendor/autoload.php';

add_action( 'plugins_loaded', function () {
    \SimPay\WooCommerce\Plugin::run();
} );