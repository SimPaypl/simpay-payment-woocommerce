<?php

namespace SimPay\WooCommerce\Factory;

use SimPay\SDK\SimPay;
use SimPay\SDK\SimPayClient;
use SimPay\WooCommerce\Api\WordPressCache;
use SimPay\WooCommerce\Api\WordPressHttpClient;
use SimPay\WooCommerce\Service\PaymentsService;
use SimPay\WooCommerce\Service\OrderService;
use SimPay\WooCommerce\Service\RefundsService;
use SimPay\WooCommerce\Database\BlikAliasRepository;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;

final class SimPayFactory
{
    private static ?SimPay $simpay = null;

    private static ?PaymentsService $payments = null;
    private static ?OrderService $orders = null;
    private static ?RefundsService $refunds = null;
    private static ?BlikAliasRepository $blikAliases = null;

    /**
     * Shared SDK entry point (one per request).
     */
    public static function sdk(): SimPay
    {
        if (self::$simpay !== null) {
            return self::$simpay;
        }

        $serviceId    = (string) SimPayGlobalSettings::get('service_id', '');
        $bearer       = (string) SimPayGlobalSettings::get('api_password', '');
        $signatureKey = (string) SimPayGlobalSettings::get('service_ipn_signature_key', '');
        $version      = defined('WC_SIMPAY_VERSION') ? WC_SIMPAY_VERSION : '1.0.0';

        self::$simpay = new SimPay(
            bearerToken: $bearer,
            serviceId: $serviceId,
            signatureKey: $signatureKey,
            platform: 'woocommerce',
            platformVersion: $version,
            httpClient: new WordPressHttpClient(),
            cache: new WordPressCache()
        );

        return self::$simpay;
    }

    /**
     * SDK API client for direct API calls.
     */
    public static function client(): SimPayClient
    {
        return self::sdk()->client();
    }

    /**
     * Business services
     */
    public static function payments(): PaymentsService
    {
        return self::$payments ??= new PaymentsService(self::sdk());
    }

    public static function orders(): OrderService
    {
        return self::$orders ??= new OrderService(self::refunds());
    }

    public static function refunds(): RefundsService
    {
        return self::$refunds ??= new RefundsService(self::client());
    }

    public static function blikAliases(): BlikAliasRepository
    {
        return self::$blikAliases ??= new BlikAliasRepository();
    }
}
