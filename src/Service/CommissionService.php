<?php

namespace SimPay\WooCommerce\Service;

use SimPay\WooCommerce\Factory\SimPayFactory;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;

/**
 * Who pays the SimPay transaction commission: the merchant (default) or the payer.
 *
 * In "payer" mode SimPay adds the commission on top of the order total on the payment gateway,
 * so the customer is informed about the fee at checkout.
 */
final class CommissionService
{
    public const MODE_MERCHANT = 'merchant';
    public const MODE_PAYER = 'payer';

    public const ORDER_META = '_simpay_commission_mode';

    private const CHANNELS_TRANSIENT = 'simpay_channel_commissions';
    private const CHANNELS_TTL = HOUR_IN_SECONDS;
    private const CHANNELS_ERROR_TTL = 5 * MINUTE_IN_SECONDS;

    public static function modes(): array
    {
        return [self::MODE_MERCHANT, self::MODE_PAYER];
    }

    public static function mode(): string
    {
        $mode = (string) SimPayGlobalSettings::get('commission_mode', self::MODE_MERCHANT);

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_MERCHANT;
    }

    public static function isPayerMode(): bool
    {
        return self::mode() === self::MODE_PAYER;
    }

    /**
     * Channels that SimPay accepts only in merchant commission mode.
     * BLIK Level 0 (code entered in the shop) and OneClick are rejected by the API with
     * HTTP 403 "Transaction is not in merchant commission mode" for payer transactions.
     */
    private const MERCHANT_ONLY_CHANNELS = ['blik-level0'];

    /**
     * Commission mode to use for a transaction on the given direct channel.
     */
    public static function modeForChannel(?string $channel): string
    {
        if ($channel !== null && in_array($channel, self::MERCHANT_ONLY_CHANNELS, true)) {
            return self::MODE_MERCHANT;
        }

        return self::mode();
    }

    public static function isPaidByPayer(\WC_Order $order): bool
    {
        return (string) $order->get_meta(self::ORDER_META) === self::MODE_PAYER;
    }

    /**
     * Fee the payer will be charged for the given channel, or null when it cannot be determined
     * (unknown channel or channel list unavailable). Returns 0.0 for channels without commission.
     */
    public static function calculateFee(float $amount, string $channel): ?float
    {
        $commissions = self::channelCommissions();
        $rates = $commissions[$channel] ?? null;

        if ($rates === null) {
            return null;
        }

        $fee = $amount * $rates['percentage'] / 100 + $rates['fixed'];

        if ($fee > 0 && $rates['minimal'] !== null && $fee < $rates['minimal']) {
            $fee = $rates['minimal'];
        }

        return round($fee, 2);
    }

    /**
     * Checkout notice for payer mode. With a known channel the exact fee is shown,
     * otherwise (e.g. the main gateway, where the channel is chosen on the payment page) a generic message.
     */
    /**
     * @param string|null $channel           Direct channel the transaction is created with (decides the commission mode).
     * @param string|null $commissionChannel Channel ID used to look up commission rates, if it differs from $channel.
     */
    public static function renderCheckoutNotice(?string $channel = null, ?string $commissionChannel = null): string
    {
        if (self::modeForChannel($channel) !== self::MODE_PAYER) {
            return '';
        }

        $channel = $commissionChannel ?? $channel;

        $text = esc_html__('A transaction fee will be added to the payment amount. The exact amount will be shown on the payment gateway.', 'simpay');

        if ($channel !== null && $channel !== '') {
            $fee = self::calculateFee(self::checkoutTotal(), $channel);

            if ($fee !== null && $fee <= 0.0) {
                return '';
            }

            if ($fee !== null) {
                $text = sprintf(
                    '%s: <strong>%s</strong>',
                    esc_html__('Transaction fee', 'simpay'),
                    wc_price($fee)
                );
            }
        }

        return '<div class="simpay-commission-notice">' . $text . '</div>';
    }

    /**
     * Customer-facing message for orders paid with the payer commission mode.
     */
    public static function orderNoticeText(): string
    {
        return __('A transaction fee was charged during payment. This fee was visible at checkout and on the payment gateway.', 'simpay');
    }

    /**
     * Channel commissions keyed by channel ID, cached for an hour.
     *
     * @return array<string, array{percentage: float, fixed: float, minimal: ?float}>
     */
    private static function channelCommissions(): array
    {
        $cached = get_transient(self::CHANNELS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $channels = SimPayFactory::client()->getChannels();
        } catch (\Throwable $e) {
            error_log('SimPay: Cannot fetch channels for commission display: ' . $e->getMessage());
            set_transient(self::CHANNELS_TRANSIENT, [], self::CHANNELS_ERROR_TTL);

            return [];
        }

        $out = [];
        foreach ((array) $channels as $channel) {
            if (!is_array($channel) || empty($channel['id'])) {
                continue;
            }

            $commissions = is_array($channel['commissions'] ?? null) ? $channel['commissions'] : [];

            $out[(string) $channel['id']] = [
                'percentage' => (float) ($commissions['percentage'] ?? $channel['commission'] ?? 0),
                'fixed' => (float) ($commissions['fixed'] ?? 0),
                'minimal' => isset($commissions['minimal']) ? (float) $commissions['minimal'] : null,
            ];
        }

        set_transient(self::CHANNELS_TRANSIENT, $out, $out === [] ? self::CHANNELS_ERROR_TTL : self::CHANNELS_TTL);

        return $out;
    }

    private static function checkoutTotal(): float
    {
        // "Pay for order" page: the order already exists.
        $orderId = absint(get_query_var('order-pay'));
        if ($orderId > 0) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                return (float) $order->get_total();
            }
        }

        return (WC()->cart) ? (float) WC()->cart->get_total('edit') : 0.0;
    }

    public static function clearCache(): void
    {
        delete_transient(self::CHANNELS_TRANSIENT);
    }
}
