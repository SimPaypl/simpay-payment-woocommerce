<?php

namespace SimPay\WooCommerce\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

class BlikPayLater extends SimPayGateways
{
    public function __construct()
    {
        parent::__construct('simpay_blik_pay_later');
    }
}