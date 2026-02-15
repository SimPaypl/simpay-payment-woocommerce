<?php

namespace SimPay\WooCommerce\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

class Blik extends SimPayGateways
{
    public function __construct()
    {
        parent::__construct('simpay_blik');
    }
}