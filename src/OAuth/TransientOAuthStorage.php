<?php

namespace SimPay\WooCommerce\OAuth;

use SimPay\SDK\OAuth\OAuthStorageInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the SimPay OAuth flow (state, PKCE verifier, temporary access token) per admin user.
 * WordPress has no PHP sessions, so short-lived per-user transients are used instead.
 */
final class TransientOAuthStorage implements OAuthStorageInterface
{
    /** Long enough to log in to SimPay and pick a service; the access token itself lives ~5 minutes. */
    private const TTL = 15 * MINUTE_IN_SECONDS;

    private int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    public function get(string $key): ?array
    {
        $value = get_transient($this->key($key));

        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value): void
    {
        set_transient($this->key($key), $value, self::TTL);
    }

    public function remove(string $key): void
    {
        delete_transient($this->key($key));
    }

    private function key(string $key): string
    {
        return sprintf('%s_%d', $key, $this->userId);
    }
}
