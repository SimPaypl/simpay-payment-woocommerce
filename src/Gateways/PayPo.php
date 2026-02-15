<?php

namespace SimPay\WooCommerce\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

class PayPo extends SimPayGateways
{
    public function __construct()
    {
        parent::__construct('simpay_paypo');
    }
}