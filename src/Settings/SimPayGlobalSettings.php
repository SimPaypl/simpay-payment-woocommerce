<?php

namespace SimPay\WooCommerce\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class SimPayGlobalSettings
{
    public const MENU_SLUG  = 'simpay-settings';
    public const OPTION_KEY = 'simpay_settings';

    /**
     * Boot the settings page hooks.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
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

        $this->add_section_header('simpay_settings_section_api', __('Configuring the SimPay connection to the store', 'simpay'), esc_html__('Configure SimPay API credentials used by all payment methods.', 'simpay'));
        $this->add_text_field('service_id', __('Service ID', 'simpay'), __('Online payments → Services → Details → Service ID', 'simpay'));
        $this->add_password_field('api_password', __('API password', 'simpay'), __('Customer account → API → Details → Password / Bearer Token', 'simpay'));
        $this->add_password_field('service_ipn_signature_key', __('IPN signature key', 'simpay'), __('Online payments → Services → Details → Settings → IPN signature key', 'simpay'));
        $this->add_checkbox_field('ipn_check_ip', __('Verify incoming IP address in IPN notifications', 'simpay'), __('If your shop is using Cloudflare, we do not recommend enabling this option.', 'simpay'));
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

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('SimPay settings', 'simpay'); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('simpay_settings_group');
                do_settings_sections('simpay-settings-admin');
                submit_button();
                ?>
            </form>
        </div>
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
     * Register a text field.
     */
    private function add_text_field(string $key, string $label, string $description = ''): void
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
            'simpay-settings-admin',
            'simpay_settings_section_api'
        );
    }

    /**
     * Register a password field.
     */
    private function add_password_field(string $key, string $label, string $description = ''): void
    {
        $section = ($key === 'webhook_secret') ? 'simpay_settings_section_webhook' : 'simpay_settings_section_api';

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
            'simpay-settings-admin',
            $section
        );
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
                    echo '<p>' . $description . '</p>';
                },
                'simpay-settings-admin'
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
