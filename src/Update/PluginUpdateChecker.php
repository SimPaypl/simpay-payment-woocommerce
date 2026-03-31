<?php

namespace SimPay\WooCommerce\Update;

class PluginUpdateChecker
{
    private const API_URL = 'https://api.simpay.pl/ecommerce/plugin/woocommerce/version/';
    private const CACHE_KEY = 'simpay_plugin_update_payload';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    private string $currentVersion;

    public function __construct(string $currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderAdminNotice']);
    }

    public function renderAdminNotice(): void
    {
        if (!current_user_can('update_plugins')) {
            return;
        }

        if (!is_admin()) {
            return;
        }

        $remote = $this->getRemoteVersionData();
        if ($remote === null) {
            return;
        }

        $update = $this->buildUpdatePayload($remote);
        if ($update === null) {
            return;
        }

        if (!version_compare($update['version'], $this->currentVersion, '>')) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            wp_kses_post(sprintf(
                /* translators: 1: current version, 2: latest version, 3: download URL */
                __('A new version of the SimPay for WooCommerce plugin is available. Current: <strong>%1$s</strong>, latest: <strong>%2$s</strong>. <a href="%3$s" target="_blank" rel="noopener noreferrer">Download ZIP package</a>.', 'simpay'),
                esc_html($this->currentVersion),
                esc_html($update['version']),
                esc_url($update['zip_url'])
            ))
        );
    }

    private function getRemoteVersionData(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !($decoded['success'] ?? false) || !is_array($decoded['data'] ?? null)) {
            return null;
        }

        $payload = $decoded['data'];
        set_site_transient(self::CACHE_KEY, $payload, self::CACHE_TTL);

        return $payload;
    }

    private function buildUpdatePayload(array $remote): ?array
    {
        $version = isset($remote['version']) ? trim((string) $remote['version']) : '';
        $zipUrl = isset($remote['zip_url']) ? trim((string) $remote['zip_url']) : '';

        if ($version === '' || $zipUrl === '') {
            return null;
        }

        return [
            'name' => isset($remote['name']) && $remote['name'] !== ''
            ? (string) $remote['name']
            : 'SimPay for WooCommerce',
            'version' => $version,
            'zip_url' => $zipUrl,
        ];
    }
}



