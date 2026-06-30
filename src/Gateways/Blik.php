<?php

namespace SimPay\WooCommerce\Gateways;

use SimPay\WooCommerce\Config\Gateways;
use SimPay\WooCommerce\Factory\SimPayFactory;
use SimPay\WooCommerce\Database\BlikAliasRepository;

if (!defined('ABSPATH')) {
    exit;
}

class Blik extends SimPayGateways
{
    public function __construct()
    {
        parent::__construct('simpay_blik');
    }

    public function init_properties(): void
    {
        parent::init_properties();
        $this->has_fields = true;
    }

    public function init_form_fields(): void
    {
        parent::init_form_fields();

        $blik_fields = [
            'blik_section' => [
                'type'  => 'title',
                'title' => __('BLIK Level 0 & OneClick settings', 'simpay'),
                'description' => __('Configure BLIK Level 0 (code on your page) and OneClick (payment without code) features. Both require activation in SimPay panel.', 'simpay'),
            ],
            'blik_level0_enabled' => [
                'title'       => __('BLIK Level 0', 'simpay'),
                'label'       => __('Enable BLIK Level 0 (code input on checkout page)', 'simpay'),
                'type'        => 'checkbox',
                'description' => __('Customer enters the 6-digit BLIK code directly on your checkout page without redirect.', 'simpay'),
                'default'     => 'no',
            ],
            'blik_oneclick_enabled' => [
                'title'       => __('BLIK OneClick', 'simpay'),
                'label'       => __('Enable BLIK OneClick (payment without code)', 'simpay'),
                'type'        => 'checkbox',
                'description' => __('Returning customers can pay with one click after registering their BLIK alias. Requires BLIK Level 0 to be enabled.', 'simpay'),
                'default'     => 'no',
            ],
            'blik_alias_label' => [
                'title'       => __('BLIK alias label', 'simpay'),
                'type'        => 'text',
                'description' => __('Label shown in customer\'s banking app when saving your shop as trusted. Must comply with BLIK certification requirements.', 'simpay'),
                'default'     => get_bloginfo('name'),
                'desc_tip'    => true,
            ],
        ];

        $this->form_fields = array_merge($this->form_fields, $blik_fields);
    }

    /**
     * Is BLIK Level 0 enabled in settings?
     */
    public function is_blik_level0_enabled(): bool
    {
        return $this->get_option('blik_level0_enabled', 'no') === 'yes';
    }

    /**
     * Is BLIK OneClick enabled in settings?
     */
    public function is_blik_oneclick_enabled(): bool
    {
        return $this->get_option('blik_oneclick_enabled', 'no') === 'yes'
            && $this->is_blik_level0_enabled();
    }

    /**
     * Get the alias label for BLIK OneClick registration.
     */
    public function get_blik_alias_label(): string
    {
        return $this->get_option('blik_alias_label', get_bloginfo('name'));
    }

    /**
     * Resolve the correct directChannel based on Level 0 setting.
     * - Level 0 enabled: 'blik-level0'
     * - Level 0 disabled: 'blik' (standard redirect)
     */
    public function get_direct_channel(): string
    {
        return $this->is_blik_level0_enabled() ? 'blik-level0' : 'blik';
    }

    /**
     * Render BLIK payment fields on checkout page.
     *
     * Implements BLIK Level 0 & OneClick certification checklist:
     * - Empty input without numeric placeholder
     * - Numeric keyboard on mobile (inputmode="numeric")
     * - Max 6 digits, no other characters
     * - No autocomplete
     * - Field not auto-focused
     * - Proper naming: "Kod BLIK"
     * - Supporting message about banking app
     * - OneClick: alias selection for logged-in users with active alias
     */
    public function payment_fields(): void
    {
        // Show description if set
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        if (!$this->is_blik_level0_enabled()) {
            return;
        }

        $customer_id = get_current_user_id();
        $active_aliases = [];

        // Check if logged-in user has active BLIK alias(es) (for OneClick)
        if ($this->is_blik_oneclick_enabled() && $customer_id > 0) {
            $repository = new BlikAliasRepository();
            $alias = $repository->findActiveByCustomerId($customer_id);
            if ($alias !== null) {
                $active_aliases[] = $alias;
            }
        }

        $has_active_alias = !empty($active_aliases);

        echo '<div id="simpay-blik-fields" class="simpay-blik-wrapper">';

        if ($has_active_alias) {
            $this->render_oneclick_fields($active_aliases);
        } else {
            $this->render_code_fields(false);
        }

        echo '</div>';
    }

    /**
     * Render OneClick fields — radio button layout:
     * - Radio: "Pay without code" (OneClick)
     * - Radio: "Enter BLIK code"
     * - Code input shown only when code radio selected
     */
    private function render_oneclick_fields(array $aliases): void
    {
        $first_alias = $aliases[0];

        // Hidden alias input
        if (count($aliases) > 1) {
            echo '<div class="simpay-blik-aliases">';
            foreach ($aliases as $index => $alias) {
                $checked = $index === 0 ? ' checked="checked"' : '';
                echo '<label class="simpay-blik-alias-label">';
                echo '<input type="radio" name="simpay_blik_alias_id" value="' . esc_attr($alias->id) . '"' . $checked . ' /> ';
                echo '<span>' . esc_html($alias->alias_label) . '</span>';
                echo '</label>';
            }
            echo '</div>';
        } else {
            echo '<input type="hidden" name="simpay_blik_alias_id" value="' . esc_attr($first_alias->id) . '" />';
        }

        // Mode radio buttons
        echo '<div class="simpay-blik-mode-selector">';

        // OneClick option
        echo '<div class="simpay-blik-radio-option simpay-blik-radio-option--active" data-mode="oneclick">';
        echo '<div class="simpay-blik-radio-row">';
        echo '<input type="radio" name="simpay_blik_mode" value="oneclick" checked="checked" class="simpay-blik-radio-input" id="simpay_blik_mode_oneclick" />';
        echo '<span class="simpay-blik-radio-dot"></span>';
        echo '<span class="simpay-blik-radio-text">';
        echo '<span class="simpay-blik-radio-title">' . esc_html__('Pay without code', 'simpay') . '</span>';
        echo '<span class="simpay-blik-radio-desc">' . esc_html__('Confirm the payment in your banking app', 'simpay') . '</span>';
        echo '</span>';
        echo '</div>';
        echo '</div>';

        // Code option
        echo '<div class="simpay-blik-radio-option" data-mode="code">';
        echo '<div class="simpay-blik-radio-row">';
        echo '<input type="radio" name="simpay_blik_mode" value="code" class="simpay-blik-radio-input" id="simpay_blik_mode_code" />';
        echo '<span class="simpay-blik-radio-dot"></span>';
        echo '<span class="simpay-blik-radio-text">';
        echo '<span class="simpay-blik-radio-title">' . esc_html__('Enter BLIK code', 'simpay') . '</span>';
        echo '<span class="simpay-blik-radio-desc">' . esc_html__('Enter the 6-digit code from your banking app', 'simpay') . '</span>';
        echo '</span>';
        echo '</div>';
        // Code input inside this block (hidden by default)
        echo '<div class="simpay-blik-code-inline" id="simpay-blik-code-fallback" style="display:none;">';
        $this->render_code_input();
        echo '</div>';
        echo '</div>';

        echo '</div>';

        // Status hint
        echo '<div class="simpay-blik-hint" data-simpay-blik-hint>';
        echo '<span class="simpay-blik-loader" data-simpay-blik-loader style="display:none;"></span>';
        echo '<span class="simpay-blik-message" data-simpay-blik-message></span>';
        echo '</div>';
    }

    /**
     * Render BLIK code fields (when no alias or Level 0 only).
     */
    private function render_code_fields(bool $hidden): void
    {
        $style = $hidden ? ' style="display:none;"' : '';
        echo '<div class="simpay-blik-code-section"' . $style . '>';
        $this->render_code_input();
        // Status hint
        echo '<div class="simpay-blik-hint" data-simpay-blik-hint>';
        echo '<span class="simpay-blik-loader" data-simpay-blik-loader style="display:none;"></span>';
        echo '<span class="simpay-blik-message" data-simpay-blik-message>';
        echo esc_html__('You can find the BLIK code in your banking app', 'simpay');
        echo '</span>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the BLIK code input field.
     *
     * Checklist requirements applied:
     * - Empty input without numeric placeholder (no 111 111 etc.)
     * - inputmode="numeric" for mobile keyboard
     * - maxlength="6", pattern="[0-9]*"
     * - autocomplete="off"
     * - Not auto-focused (user must tap/click)
     * - Helper text about banking app
     */
    private function render_code_input(): void
    {
        echo '<div class="simpay-blik-code-wrapper">';

        // Label
        echo '<label for="simpay_blik_code" class="simpay-blik-code-label">';
        echo esc_html__('BLIK code', 'simpay');
        echo '</label>';

        // Input field
        echo '<input type="text" ';
        echo 'id="simpay_blik_code" ';
        echo 'name="simpay_blik_code" ';
        echo 'inputmode="numeric" ';
        echo 'pattern="[0-9]*" ';
        echo 'maxlength="7" ';
        echo 'autocomplete="off" ';
        echo 'class="simpay-blik-code-input" ';
        echo '/>';


        echo '</div>';
    }


    /**
     * Check if this is the user's first OneClick payment (for one-time info message).
     */
    private function is_first_oneclick_payment(): bool
    {
        $customer_id = get_current_user_id();
        if ($customer_id <= 0) {
            return false;
        }

        $shown = get_user_meta($customer_id, '_simpay_blik_oneclick_info_shown', true);
        return empty($shown);
    }

    /**
     * Mark that the OneClick info message has been shown.
     */
    public function mark_oneclick_info_shown(): void
    {
        $customer_id = get_current_user_id();
        if ($customer_id > 0) {
            update_user_meta($customer_id, '_simpay_blik_oneclick_info_shown', '1');
        }
    }

    /**
     * Validate BLIK code field on checkout.
     *
     * Checklist: Empty field error message: "Podaj kod BLIK"
     */
    public function validate_fields(): bool
    {
        if (!$this->is_blik_level0_enabled()) {
            return true;
        }

        $mode = sanitize_text_field($_POST['simpay_blik_mode'] ?? 'code');

        // OneClick mode - no code needed
        if ($mode === 'oneclick') {
            // Verify user is logged in (OneClick only for logged-in users per checklist)
            if (!is_user_logged_in()) {
                wc_add_notice(__('BLIK payment without code is only available for logged-in users.', 'simpay'), 'error');
                return false;
            }
            return true;
        }

        $blik_code = preg_replace('/\s+/', '', sanitize_text_field($_POST['simpay_blik_code'] ?? ''));

        error_log('SimPay BLIK validate: raw=' . ($_POST['simpay_blik_code'] ?? 'NOT_SET') . ' clean=' . $blik_code);

        if (empty($blik_code)) {
            wc_add_notice(__('Enter the BLIK code', 'simpay'), 'error');
            return false;
        }

        if (!preg_match('/^\d{6}$/', $blik_code)) {
            wc_add_notice(__('Incorrect BLIK code was entered. Try again.', 'simpay'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Override process_payment for BLIK Level 0.
     *
     * Creates transaction and sends BLIK code. On success, returns a special
     * redirect URL with #simpay-blik-pending fragment that JS intercepts
     * to show the "confirm in app" overlay before actual navigation.
     */
    public function process_payment($order_id)
    {
        if (!$this->is_blik_level0_enabled()) {
            return parent::process_payment($order_id);
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof \WC_Order) {
            wc_add_notice(__('SimPay error: Order not found.', 'simpay'), 'error');
            return ['result' => 'failure'];
        }

        $customer = new \WC_Customer((int) $order->get_customer_id());

        try {
            // 1. Create transaction with blik-level0 directChannel
            $payments = SimPayFactory::payments();
            $result = $payments->createTransaction($order, $customer, $this->id, $this->get_return_url($order), $this->get_direct_channel());

            if (!isset($result['transaction_id'])) {
                return ['result' => 'failure'];
            }

            $transactionId = $result['transaction_id'];

            // 2. Send BLIK code (or OneClick alias) to API
            $mode = sanitize_text_field($_POST['simpay_blik_mode'] ?? 'code');
            $blikCode = preg_replace('/\s+/', '', sanitize_text_field($_POST['simpay_blik_code'] ?? ''));

            error_log('SimPay BLIK: mode=' . $mode . ', blikCode=' . $blikCode . ', POST=' . json_encode(array_intersect_key($_POST, array_flip(['simpay_blik_mode', 'simpay_blik_code', 'simpay_blik_alias_id']))));

            // If user provided a BLIK code, always treat as code mode regardless of radio state
            if (!empty($blikCode) && preg_match('/^\d{6}$/', $blikCode)) {
                $mode = 'code';
            }

            if ($mode === 'oneclick') {
                $aliasId = (int) ($_POST['simpay_blik_alias_id'] ?? 0);
                $blikResult = \SimPay\WooCommerce\Blik\BlikPaymentHandler::processBlikOneClick(
                    $order, $transactionId, $aliasId, $this
                );
            } else {
                // User is paying with code — never attach alias to this request
                $blikResult = \SimPay\WooCommerce\Blik\BlikPaymentHandler::processBlikLevel0(
                    $order, $transactionId, $blikCode, $this, true
                );
            }

            // 3. Handle result
            if ($blikResult['result'] === 'failure') {
                $errorMessage = $blikResult['blik_message'] ?? __('Payment failed. Try again.', 'simpay');
                wc_add_notice($errorMessage, 'error');
                $order->update_status('pending', 'BLIK code rejected: ' . ($blikResult['blik_error'] ?? 'unknown'));
                $order->save();
                return ['result' => 'failure'];
            }

            // 4. Code accepted — return redirect with special fragment
            // JS will intercept this, show overlay, poll, then navigate
            $redirect = add_query_arg('simpay_blik_pending', $order_id, $this->get_return_url($order));

            return [
                'result'   => 'success',
                'redirect' => $redirect,
            ];
        } catch (\Throwable $e) {
            wc_add_notice('SimPay error: ' . $e->getMessage(), 'error');
            return ['result' => 'failure'];
        }
    }
}