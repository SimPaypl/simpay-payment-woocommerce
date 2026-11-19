<?php

namespace SimPay\WooCommerce\Settings;

use SimPay\WooCommerce\OAuth\OAuthController;
use SimPay\WooCommerce\Service\CommissionService;

if (!defined('ABSPATH')) {
    exit;
}

final class SimPayGlobalSettings
{
    public const MENU_SLUG  = 'simpay-settings';
    public const OPTION_KEY = 'simpay_settings';

    public const AUTH_METHOD_OAUTH  = 'oauth';
    public const AUTH_METHOD_MANUAL = 'manual';

    /** Settings page slug for the API credential fields (rendered separately, inside the "manual" tab). */
    private const CREDENTIALS_PAGE    = 'simpay-settings-credentials';
    private const CREDENTIALS_SECTION = 'simpay_settings_section_credentials';

    /**
     * Boot the settings page hooks.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        wp_enqueue_style('simpay-admin-settings', WC_SIMPAY_URL . 'assets/css/simpay-admin-settings.css', [], WC_SIMPAY_VERSION);
        wp_enqueue_script('simpay-admin-settings', WC_SIMPAY_URL . 'assets/js/simpay-admin-settings.js', [], WC_SIMPAY_VERSION, true);
    }

    /**
     * Add submenu page under WooCommerce.
     */
    public function add_menu_page(): void
    {
        $capability = apply_filters('simpay_settings_capability', 'manage_woocommerce');

        add_submenu_page(
            'woocommerce',
            __('SimPay settings', 'simpay'),
            __('SimPay settings', 'simpay'),
            $capability,
            self::MENU_SLUG,
            [$this, 'render_page'],
            100
        );
    }

    /**
     * Register option + fields (WordPress Settings API).
     */
    public function register_settings(): void
    {
        register_setting(
            'simpay_settings_group',     // option group
            self::OPTION_KEY,            // option name
            [$this, 'sanitize']          // sanitize callback
        );

        // Credentials: rendered by render_page() inside the "Enter credentials manually" tab.
        add_settings_section(self::CREDENTIALS_SECTION, '', '__return_null', self::CREDENTIALS_PAGE);
        $this->add_text_field('service_id', __('Service ID', 'simpay'), __('Online payments → Services → Details → Service ID', 'simpay'), self::CREDENTIALS_SECTION);
        $this->add_password_field('api_password', __('API password', 'simpay'), __('Customer account → API → Details → Password / Bearer Token', 'simpay'), self::CREDENTIALS_SECTION);
        $this->add_password_field('service_ipn_signature_key', __('IPN signature key', 'simpay'), __('Online payments → Services → Details → Settings → IPN signature key', 'simpay'), self::CREDENTIALS_SECTION);

        $this->add_section_header('simpay_settings_section_api', __('Payment settings', 'simpay'));
        $this->add_checkbox_field('ipn_check_ip', __('Verify incoming IP address in IPN notifications', 'simpay'), __('If your shop is using Cloudflare, we do not recommend enabling this option.', 'simpay'));
        $this->add_select_field(
            'commission_mode',
            __('Commission paid by', 'simpay'),
            [
                CommissionService::MODE_MERCHANT => __('Merchant', 'simpay'),
                CommissionService::MODE_PAYER    => __('Payer', 'simpay'),
            ],
            CommissionService::MODE_MERCHANT,
            __('Choose who pays the transaction commission — the merchant or the payer. In payer mode the commission is added to the payment amount and the customer is informed about it at checkout.', 'simpay')
        );
        $this->add_section_header(
                'simpay_settings_section_webhook',
                __('Webhooks', 'simpay'),
                sprintf(
                        esc_html__('Set this URL in SimPay panel as webhook/notification URL: %s', 'simpay'),
                '<code>' . esc_url(\WC()->api_request_url('simpay')) . '</code>'
        ));
    }

    /**
     * Render admin page.
     */
    public function render_page(): void
    {
        if (!current_user_can(apply_filters('simpay_settings_capability', 'manage_woocommerce'))) {
            wp_die(esc_html__('You do not have permission to access this page.', 'simpay'));
        }

        $notice = OAuthController::pullNotice();
        $pending = OAuthController::pendingConnection();
        $authMethod = self::authMethod();
        $connected = self::isOAuthConnected();
        $httpsOk = OAuthController::isRedirectUriAllowed();

        ?>
        <div class="wrap simpay-settings">
            <h1><?php echo esc_html__('SimPay settings', 'simpay'); ?></h1>

            <?php settings_errors(); ?>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($pending !== null) : ?>
                <?php $this->render_service_picker($pending); ?>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('simpay_settings_group'); ?>

                <h2><?php echo esc_html__('Configuring the SimPay connection to the store', 'simpay'); ?></h2>

                <input type="hidden" id="simpay-auth-method" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auth_method]" value="<?php echo esc_attr($authMethod); ?>" />

                <nav class="nav-tab-wrapper simpay-auth-tabs">
                    <a href="#" class="nav-tab" data-simpay-auth="<?php echo esc_attr(self::AUTH_METHOD_OAUTH); ?>"><?php echo esc_html__('Connect via SimPay (recommended)', 'simpay'); ?></a>
                    <a href="#" class="nav-tab" data-simpay-auth="<?php echo esc_attr(self::AUTH_METHOD_MANUAL); ?>"><?php echo esc_html__('Enter credentials manually', 'simpay'); ?></a>
                </nav>

                <div class="simpay-auth-panel" data-simpay-auth-panel="<?php echo esc_attr(self::AUTH_METHOD_OAUTH); ?>">
                    <?php if ($connected) : ?>
                        <div class="simpay-oauth-status">
                            <p class="simpay-oauth-status__title"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__('Your shop is connected to SimPay.', 'simpay'); ?></p>
                            <table class="simpay-oauth-status__details">
                                <?php if ((string) self::get('oauth_account_email', '') !== '') : ?>
                                    <tr><th><?php echo esc_html__('Account:', 'simpay'); ?></th><td><?php echo esc_html((string) self::get('oauth_account_email', '')); ?></td></tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php echo esc_html__('Payment service:', 'simpay'); ?></th>
                                    <td>
                                        <?php echo esc_html((string) (self::get('oauth_service_name', '') ?: self::get('service_id', ''))); ?>
                                        <span class="description">(ID: <?php echo esc_html((string) self::get('service_id', '')); ?>)</span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    <?php else : ?>
                        <p class="simpay-oauth-lead"><?php echo esc_html__('Log in to your SimPay account and grant access - the plugin will automatically create an API key, set the IPN URL and configure the IPN signature key. No copying of keys required.', 'simpay'); ?></p>
                    <?php endif; ?>

                    <?php if (!$httpsOk) : ?>
                        <div class="notice notice-warning inline">
                            <p>
                                <?php echo esc_html__('Connecting via SimPay requires the shop admin panel to be served over HTTPS. Enter the credentials manually instead.', 'simpay'); ?><br>
                                <small><?php echo esc_html__('Detected callback URL:', 'simpay'); ?> <code><?php echo esc_html((string) strtok(OAuthController::redirectUri(), '?')); ?></code></small>
                            </p>
                        </div>
                    <?php endif; ?>

                    <p class="simpay-oauth-actions">
                        <?php if ($httpsOk) : ?>
                            <a href="<?php echo esc_url(OAuthController::actionUrl('connect')); ?>" class="button <?php echo $connected ? 'button-secondary' : 'button-primary button-hero'; ?>">
                                <?php echo $connected ? esc_html__('Reconnect / change service', 'simpay') : esc_html__('Connect with SimPay', 'simpay'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($connected) : ?>
                            <a href="<?php echo esc_url(OAuthController::actionUrl('disconnect')); ?>" class="button simpay-button-danger"
                               onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Are you sure you want to disconnect the shop from SimPay? SimPay payments will stop working until you connect again.', 'simpay'))); ?>);">
                                <?php echo esc_html__('Disconnect', 'simpay'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="simpay-auth-panel" data-simpay-auth-panel="<?php echo esc_attr(self::AUTH_METHOD_MANUAL); ?>">
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields(self::CREDENTIALS_PAGE, self::CREDENTIALS_SECTION); ?>
                    </table>
                </div>

                <?php
                do_settings_sections('simpay-settings-admin');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Service picker shown after a successful OAuth callback.
     */
    private function render_service_picker(\SimPay\SDK\OAuth\PendingConnection $pending): void
    {
        $suggested = $pending->suggestServiceId(OAuthController::shopUrl());
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="simpay-oauth-picker">
            <input type="hidden" name="action" value="simpay_oauth_provision" />
            <?php OAuthController::nonceField(); ?>

            <h2><?php echo esc_html__('Select a SimPay payment service', 'simpay'); ?></h2>

            <?php if ($pending->accountEmail !== '') : ?>
                <p><?php echo esc_html__('Connected SimPay account:', 'simpay'); ?> <strong><?php echo esc_html($pending->accountEmail); ?></strong></p>
            <?php endif; ?>

            <p><?php echo esc_html__('Choose the payment service this shop should use:', 'simpay'); ?></p>

            <fieldset>
                <?php foreach ($pending->services as $service) : ?>
                    <label class="simpay-oauth-picker__service">
                        <input type="radio" name="service_id" value="<?php echo esc_attr($service->id); ?>" <?php checked($service->id, $suggested); ?> required />
                        <strong><?php echo esc_html($service->getDisplayName()); ?></strong>
                        <span class="description">
                            &middot; ID: <?php echo esc_html($service->id); ?>
                            <?php if ($service->url !== '') : ?>&middot; <?php echo esc_html($service->url); ?><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="notice notice-warning inline">
                <p><?php echo esc_html__('The plugin will create a new API key, set the IPN URL and generate a new IPN signature key for the selected service. The previous IPN signature key will stop working immediately - if this service is also used by another shop or integration, update it there as well.', 'simpay'); ?></p>
            </div>

            <p>
                <a href="<?php echo esc_url(OAuthController::actionUrl('cancel')); ?>" class="button"><?php echo esc_html__('Cancel', 'simpay'); ?></a>
                <button type="submit" class="button button-primary"><?php echo esc_html__('Connect selected service', 'simpay'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Sanitize settings before saving.
     *
     * @param array $input
     * @return array
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        $out = [];

        // Sanitize scalar fields
        $out['service_id']                 = isset($input['service_id']) ? preg_replace('/\s+/', '', sanitize_text_field($input['service_id'])) : '';
        $out['api_password']               = isset($input['api_password']) ? preg_replace('/\s+/', '', sanitize_text_field($input['api_password'])) : '';
        $out['service_ipn_signature_key']  = isset($input['service_ipn_signature_key']) ? preg_replace('/\s+/', '', sanitize_text_field($input['service_ipn_signature_key'])) : '';
        $out['ipn_check_ip']               = !empty($input['ipn_check_ip']) ? '1' : '0';

        $commissionMode = isset($input['commission_mode']) ? sanitize_key($input['commission_mode']) : '';
        $out['commission_mode'] = in_array($commissionMode, CommissionService::modes(), true)
            ? $commissionMode
            : CommissionService::MODE_MERCHANT;

        // Connection method + OAuth info. Fields missing from the input (the settings form does not post
        // the OAuth info) keep their stored values.
        $authMethod = isset($input['auth_method']) ? sanitize_key($input['auth_method']) : '';
        $out['auth_method'] = in_array($authMethod, [self::AUTH_METHOD_OAUTH, self::AUTH_METHOD_MANUAL], true)
            ? $authMethod
            : self::authMethod();
        $out['oauth_account_email'] = array_key_exists('oauth_account_email', $input)
            ? sanitize_email((string) $input['oauth_account_email'])
            : (string) self::get('oauth_account_email', '');
        $out['oauth_service_name'] = array_key_exists('oauth_service_name', $input)
            ? sanitize_text_field((string) $input['oauth_service_name'])
            : (string) self::get('oauth_service_name', '');

        // Credentials may point to another service with different channels/commissions.
        CommissionService::clearCache();

        return $out;
    }

    /**
     * Helper: read a setting value.
     */
    public static function get(string $key, $default = null)
    {
        $options = get_option(self::OPTION_KEY, []);

        if (!is_array($options)) {
            return $default;
        }

        return $options[$key] ?? $default;
    }

    /**
     * Merge values into the stored settings (runs through sanitize()).
     *
     * @param array<string, string> $values
     */
    public static function update(array $values): void
    {
        $options = get_option(self::OPTION_KEY, []);
        $options = is_array($options) ? $options : [];

        update_option(self::OPTION_KEY, array_merge($options, $values));
    }

    /**
     * Stored connection method; shops without credentials default to OAuth,
     * installations configured before OAuth existed default to manual.
     */
    public static function authMethod(): string
    {
        $method = (string) self::get('auth_method', '');
        if (in_array($method, [self::AUTH_METHOD_OAUTH, self::AUTH_METHOD_MANUAL], true)) {
            return $method;
        }

        return (string) self::get('api_password', '') === '' ? self::AUTH_METHOD_OAUTH : self::AUTH_METHOD_MANUAL;
    }

    public static function isOAuthConnected(): bool
    {
        return self::authMethod() === self::AUTH_METHOD_OAUTH && (string) self::get('api_password', '') !== '';
    }

    /**
     * Register a text field.
     */
    private function add_text_field(string $key, string $label, string $description = '', string $section = 'simpay_settings_section_api'): void
    {
        add_settings_field(
            $key,
            esc_html($label),
            function () use ($key, $description) {
                $value = esc_attr((string) self::get($key, ''));
                printf(
                    '<input type="text" class="regular-text" name="%s[%s]" value="%s" /><br>',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    $value
                );

                if ($description) {
                    printf('<p class="description">%s</p>', wp_kses_post($description));
                }
            },
            self::page_for_section($section),
            $section
        );
    }

    /**
     * Register a password field.
     */
    private function add_password_field(string $key, string $label, string $description = '', string $section = 'simpay_settings_section_api'): void
    {
        add_settings_field(
            $key,
            esc_html($label),
            function () use ($key, $description) {
                $value = esc_attr((string) self::get($key, ''));
                printf(
                    '<input type="password" class="regular-text" name="%s[%s]" value="%s" autocomplete="new-password" /><br>',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    $value
                );

                if ($description) {
                    printf('<p class="description">%s</p>', wp_kses_post($description));
                }
            },
            self::page_for_section($section),
            $section
        );
    }

    private static function page_for_section(string $section): string
    {
        return $section === self::CREDENTIALS_SECTION ? self::CREDENTIALS_PAGE : 'simpay-settings-admin';
    }

    /**
     * Register a settings section header.
     */
    private function add_section_header(string $key, string $label, string $description = ''): void
    {
        add_settings_section(
                $key,
                esc_html($label),
                function () use ($description) {
                    if ($description !== '') {
                        echo '<p>' . $description . '</p>';
                    }
                },
                'simpay-settings-admin'
        );
    }

    /**
     * Register a select field.
     *
     * @param array<string, string> $choices value => label
     */
    private function add_select_field(string $key, string $label, array $choices, string $default, string $description = ''): void
    {
        add_settings_field(
            $key,
            esc_html($label),
            function () use ($key, $choices, $default, $description) {
                $current = (string) self::get($key, $default);

                printf('<select name="%s[%s]">', esc_attr(self::OPTION_KEY), esc_attr($key));
                foreach ($choices as $value => $choiceLabel) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($value),
                        selected($current, $value, false),
                        esc_html($choiceLabel)
                    );
                }
                echo '</select>';

                if ($description) {
                    printf('<p class="description">%s</p>', wp_kses_post($description));
                }
            },
            'simpay-settings-admin',
            'simpay_settings_section_api'
        );
    }

    /**
     * Register a checkbox field.
     */
    private function add_checkbox_field(
            string $key,
            string $label,
            string $description = '',
            string $section = 'simpay_settings_section_api'
    ): void {
        add_settings_field(
                $key,
                esc_html($label),
                function () use ($key, $description) {
                    $value = (string) self::get($key, '0');
                    $checked = checked($value, '1', false);

                    printf(
                            '<label><input type="checkbox" name="%s[%s]" value="1" %s /></label>',
                            esc_attr(self::OPTION_KEY),
                            esc_attr($key),
                            $checked,
                    );

                    if ($description) {
                        printf('<p class="description">%s</p>', wp_kses_post($description));
                    }
                },
                'simpay-settings-admin',
                $section
        );
    }

}
