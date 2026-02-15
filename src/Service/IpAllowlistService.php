<?php

namespace SimPay\WooCommerce\Service;

use SimPay\WooCommerce\Api\SimPayApiClient;

final class IpAllowlistService
{
    private const CACHE_KEY = 'simpay_ip_allowlist';
    private const TTL = 900; // 15 min

    private SimPayApiClient $api;

    public function __construct(SimPayApiClient $api)
    {
        $this->api = $api;
    }

    public function isAllowed(string $ip): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return false;
        }

        $ips = get_transient(self::CACHE_KEY);

        if (!is_array($ips)) {
            try {
                $ips = $this->api->getAllowedIps();
                set_transient(self::CACHE_KEY, $ips, self::TTL);
            } catch (\Throwable $e) {
                // If the API goes down, we do NOT block the IPN
                return true;
            }
        }

        return in_array($ip, $ips, true);
    }
}