<?php

namespace SimPay\WooCommerce\OAuth;

use SimPay\SDK\Exception\ApiException;
use SimPay\SDK\OAuth\OAuthClient;
use SimPay\SDK\OAuth\OAuthConnector;
use SimPay\SDK\OAuth\OAuthException;
use SimPay\SDK\OAuth\PendingConnection;
use SimPay\WooCommerce\Api\WordPressHttpClient;
use SimPay\WooCommerce\Service\CommissionService;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Connect with SimPay" endpoints (admin-post.php). The flow itself lives in simpay/ecommerce
 * (OAuthConnector); this class only wires it to WordPress: storage, routing, settings and messages.
 *
 * Flow: connect -> SimPay consent screen -> callback -> service picker (settings page) -> provision.
 */
final class OAuthController
{
    /** OAuth client registered by SimPay for the WooCommerce plugin (public client, PKCE). */
    public const DEFAULT_CLIENT_ID = '01a1e47b-6bca-7211-ac08-a8fa772c36f9';

    private const NONCE_ACTION = 'simpay_oauth';
    private const NOTICE_TRANSIENT = 'simpay_oauth_notice';

    public static function register(): void
    {
        foreach (['connect', 'callback', 'provision', 'disconnect', 'cancel'] as $action) {
            add_action('admin_post_simpay_oauth_' . $action, [self::class, $action]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // URLs / state used by the settings page
    // ──────────────────────────────────────────────────────────────────────

    public static function actionUrl(string $action): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=simpay_oauth_' . $action), self::NONCE_ACTION);
    }

    public static function nonceField(): void
    {
        wp_nonce_field(self::NONCE_ACTION);
    }

    /**
     * Callback URL built from the configured admin URL (not the current request), so that a shop behind
     * a TLS-terminating proxy still produces an https:// URL.
     */
    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=simpay_oauth_callback', 'admin');
    }

    public static function isRedirectUriAllowed(): bool
    {
        return OAuthClient::isRedirectUriAllowed(self::redirectUri());
    }

    public static function pendingConnection(): ?PendingConnection
    {
        return self::connector()->getPendingConnection();
    }

    public static function shopUrl(): string
    {
        return home_url('/');
    }

    /**
     * One-time admin notice set by the endpoints and shown on the settings page.
     *
     * @return array{type: string, message: string}|null
     */
    public static function pullNotice(): ?array
    {
        $key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);

        return is_array($notice) ? $notice : null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Endpoints
    // ──────────────────────────────────────────────────────────────────────

    public static function connect(): void
    {
        self::authorize(true);

        try {
            $url = self::connector()->start(self::redirectUri());
        } catch (\Throwable $e) {
            self::failAndRedirect($e);
        }

        // External redirect to the SimPay consent screen (wp_safe_redirect would block it).
        wp_redirect($url);
        exit;
    }

    /**
     * Redirect back from SimPay. No nonce here: CSRF protection is the OAuth "state" verified by the SDK.
     */
    public static function callback(): void
    {
        self::authorize(false);

        try {
            self::connector()->handleCallback(wp_unslash($_GET));
        } catch (\Throwable $e) {
            self::failAndRedirect($e);
        }

        // The service picker is rendered on the settings page from the pending connection.
        self::redirectToSettings();
    }

    public static function provision(): void
    {
        self::authorize(true);

        $serviceId = sanitize_text_field(wp_unslash((string) ($_POST['service_id'] ?? '')));

        try {
            $result = self::connector()->connect(
                $serviceId,
                \WC()->api_request_url('simpay'),
                'WooCommerce ' . (string) wp_parse_url(self::shopUrl(), PHP_URL_HOST)
            );
        } catch (\Throwable $e) {
            self::failAndRedirect($e);
        }

        SimPayGlobalSettings::update([
            'api_password' => $result->apiKey,
            'service_id' => $result->serviceId,
            'service_ipn_signature_key' => $result->ipnSignatureKey,
            'auth_method' => SimPayGlobalSettings::AUTH_METHOD_OAUTH,
            'oauth_account_email' => $result->accountEmail,
            'oauth_service_name' => $result->serviceName,
        ]);

        self::notice('success', sprintf(
            /* translators: %s: SimPay payment service name */
            __('The shop has been connected to SimPay service "%s". API key, IPN URL and IPN signature key were configured automatically.', 'simpay'),
            $result->serviceName
        ));

        self::redirectToSettings();
    }

    /**
     * Removes OAuth-provisioned credentials from the shop.
     * The API has no endpoint for deleting API keys / revoking access, so the key stays in the SimPay panel.
     */
    public static function disconnect(): void
    {
        self::authorize(true);

        self::connector()->cancel();

        SimPayGlobalSettings::update([
            'api_password' => '',
            'service_id' => '',
            'service_ipn_signature_key' => '',
            'auth_method' => SimPayGlobalSettings::AUTH_METHOD_OAUTH,
            'oauth_account_email' => '',
            'oauth_service_name' => '',
        ]);

        self::notice('success', __('The shop has been disconnected from SimPay. SimPay payments will not work until you connect again or enter the credentials manually.', 'simpay'));

        self::redirectToSettings();
    }

    public static function cancel(): void
    {
        self::authorize(true);

        self::connector()->cancel();

        self::redirectToSettings();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private static function connector(): OAuthConnector
    {
        return OAuthConnector::create(
            (string) apply_filters('simpay_oauth_client_id', self::DEFAULT_CLIENT_ID),
            new TransientOAuthStorage(get_current_user_id()),
            new WordPressHttpClient()
        );
    }

    private static function authorize(bool $checkNonce): void
    {
        if (!current_user_can(apply_filters('simpay_settings_capability', 'manage_woocommerce'))) {
            wp_die(esc_html__('You do not have permission to access this page.', 'simpay'), 403);
        }

        if ($checkNonce) {
            check_admin_referer(self::NONCE_ACTION);
        }
    }

    /**
     * @return never
     */
    private static function failAndRedirect(\Throwable $e): void
    {
        error_log('SimPay OAuth error: ' . $e->getMessage());

        self::notice('error', self::errorMessage($e));
        self::redirectToSettings();
    }

    private static function errorMessage(\Throwable $e): string
    {
        if ($e instanceof OAuthException) {
            switch ($e->getReason()) {
                case OAuthException::REDIRECT_URI_NOT_ALLOWED:
                    return __('Connecting via SimPay requires the shop admin panel to be served over HTTPS. Enter the credentials manually instead.', 'simpay');
                case OAuthException::ACCESS_DENIED:
                    return __('Connection with SimPay was cancelled.', 'simpay');
                case OAuthException::AUTHORIZATION_FAILED:
                    /* translators: %s: error returned by SimPay */
                    return sprintf(__('SimPay authorization failed: %s', 'simpay'), (string) $e->getDetails());
                case OAuthException::INVALID_STATE:
                    return __('Invalid or expired SimPay authorization. Please try connecting again.', 'simpay');
                case OAuthException::NO_PAYMENT_SERVICES:
                    return __('No active payment services were found on your SimPay account. Create a payment service in the SimPay panel first.', 'simpay');
                case OAuthException::EXPIRED:
                    return __('The SimPay authorization has expired. Please connect again.', 'simpay');
                case OAuthException::UNKNOWN_SERVICE:
                    return __('Please select a payment service.', 'simpay');
                case OAuthException::UNSUPPORTED_IPN_VERSION:
                    /* translators: %s: IPN version configured for the service */
                    return sprintf(__('The selected service uses IPN version %s, but this plugin supports IPN 2.0 only. Change the IPN version to 2.0 in the service settings in the SimPay panel, then select the service again. Nothing was changed.', 'simpay'), (string) $e->getDetails());
                default:
                    return $e->getMessage();
            }
        }

        /* translators: %s: error message */
        return sprintf(
            __('Could not connect to SimPay: %s', 'simpay'),
            $e instanceof ApiException && $e->getApiMessage() ? $e->getApiMessage() : $e->getMessage()
        );
    }

    private static function notice(string $type, string $message): void
    {
        set_transient(
            self::NOTICE_TRANSIENT . '_' . get_current_user_id(),
            ['type' => $type, 'message' => $message],
            5 * MINUTE_IN_SECONDS
        );
    }

    /**
     * @return never
     */
    private static function redirectToSettings(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . SimPayGlobalSettings::MENU_SLUG));
        exit;
    }
}
