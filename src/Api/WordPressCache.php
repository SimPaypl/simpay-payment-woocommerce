<?php

namespace SimPay\WooCommerce\Api;

use SimPay\SDK\CacheInterface;

/**
 * WordPress transient-based cache adapter for the SimPay SDK.
 */
final class WordPressCache implements CacheInterface
{
    public function get(string $key): mixed
    {
        $value = get_transient($key);

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        set_transient($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        delete_transient($key);
    }
}

