<?php

namespace SimPay\WooCommerce\Config;

final class Gateways
{
    public static function all(): array
    {
        $list = [
            'simpay_payment' => [
                'class' => \SimPay\WooCommerce\Gateways\SimPay::class,
                'name' => __('SimPay - payment gateway', 'simpay'),
                'front_name' => __('Online payment by SimPay', 'simpay'),
                'default_description' => __('Choose payment method.', 'simpay'),
                'default_enabled' => 'yes',
                'api' => 'PBL_ID',
            ],
            'simpay_blik' => [
                'class' => \SimPay\WooCommerce\Gateways\Blik::class,
                'name' => __('SimPay - BLIK', 'simpay'),
                'front_name' => __('Online payment by BLIK', 'simpay'),
                'default_description' => '',
                'default_enabled' => 'no',
                'api' => 'blik'
            ],
            'simpay_blik_pay_later' => [
                'class' => \SimPay\WooCommerce\Gateways\BlikPayLater::class,
                'name' => __('SimPay - BLIK Pay Later', 'simpay'),
                'front_name' => __('Online payment by BLIK Pay Later', 'simpay'),
                'default_description' => '',
                'default_enabled' => 'no',
                'api' => 'blik-paylater'
            ],
            'simpay_paypo' => [
                'class' => \SimPay\WooCommerce\Gateways\PayPo::class,
                'name' => __('SimPay - PayPo', 'simpay'),
                'front_name' => __('Online payment by PayPo', 'simpay'),
                'default_description' => '',
                'default_enabled' => 'no',
                'api' => 'paypo'
            ],
        ];

        return apply_filters('simpay_gateway_list', $list);
    }

    public static function get(string $id, ?string $name = null): ?string
    {
        if ($name && isset(self::all()[$id][$name])) {
            return self::all()[$id][$name];
        } else {
            return null;
        }
    }
}
