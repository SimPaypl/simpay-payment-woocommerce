<?php

namespace SimPay\WooCommerce\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

class SimPay extends SimPayGateways
{
    public function __construct()
    {
        parent::__construct('simpay_payment');
    }
}