<?php


namespace SimPay\WooCommerce\Factory;

use SimPay\WooCommerce\Api\SimPayApiClient;
use SimPay\WooCommerce\Api\WordPressHttpClient;
use SimPay\WooCommerce\Service\PaymentsService;
use SimPay\WooCommerce\Service\OrderService;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;

final class SimPayFactory
{
    private static ?WordPressHttpClient $http = null;
    private static ?SimPayApiClient $api = null;

    private static ?PaymentsService $payments = null;
    private static ?OrderService $orders = null;
    /**
     * Shared WP HTTP client (one per request).
     */
    public static function http(): WordPressHttpClient
    {
        if (self::$http) {
            return self::$http;
        }

        self::$http = new WordPressHttpClient();

        return self::$http;
    }

    /**
     * Shared SimPay API client (one per request).
     */
    public static function api(): SimPayApiClient
    {
        if (self::$api) {
            return self::$api;
        }

        $serviceId = (string) SimPayGlobalSettings::get('service_id', '');
        $bearer = (string) SimPayGlobalSettings::get('api_password', '');

        self::$api = new SimPayApiClient(self::http(), $serviceId, $bearer);

        return self::$api;
    }

    /**
     * Business services
     */
    public static function payments(): PaymentsService
    {
        return self::$payments ??= new PaymentsService(self::api());
    }

    public static function orders(): OrderService
    {
        return self::$orders ??= new OrderService();
    }
}
