<?php

namespace SimPay\WooCommerce\Blik;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BLIK error codes mapping to user-friendly messages.
 * Based on BLIK Level 0 & OneClick certification checklist.
 */
final class BlikErrorMessages
{
    /**
     * Error codes that indicate an invalid/expired/used BLIK code.
     * User should retry with a new code.
     */
    private const CODE_ERRORS = [
        'INVALID_BLIK_CODE',
        'INVALID_BLIK_CODE_FORMAT',
        'BLIK_CODE_EXPIRED',
        'BLIK_CODE_CANCELLED',
        'BLIK_CODE_USED',
        'PAYER_APP_NOT_ACTIVE',
        'PAYER_APP_NOT_FOUND',
    ];

    /**
     * Errors where user should check banking app for reason.
     */
    private const BANKING_APP_ERRORS = [
        'INSUFFICIENT_FUNDS',
        'LIMIT_EXCEEDED',
        'SEC_DECLINED',
    ];

    /**
     * Errors where user rejected in banking app.
     */
    private const USER_DECLINED_ERRORS = [
        'USER_DECLINED',
    ];

    /**
     * Timeout errors.
     */
    private const TIMEOUT_ERRORS = [
        'TIMEOUT',
        'USER_TIMEOUT',
        'AM_TIMEOUT',
    ];

    /**
     * Alias-specific errors that require falling back to code.
     */
    private const ALIAS_ERRORS = [
        'ALIAS_DECLINED',
        'ALIAS_NOT_FOUND',
    ];

    /**
     * Amount too high for alias payment.
     */
    private const AMOUNT_ERRORS = [
        'ER_DATAAMT_HUGE',
    ];

    /**
     * General/system errors.
     */
    private const GENERAL_ERRORS = [
        'GENERAL_ERROR',
        'SYSTEM_ERROR',
        'TAS_DECLINED',
        'ISSUER_DECLINED',
        'BLIK_GENERAL_ERROR',
        'BLIK_TECHNICAL_BREAK',
        'BLIK_CODE_NOT_SUPPORTED',
        'BLIK_CODE_LIMIT',
    ];

    /**
     * Get user-friendly error message for a given BLIK error code.
     */
    public static function getMessage(string $errorCode): string
    {
        if (in_array($errorCode, self::CODE_ERRORS, true)) {
            return __('Incorrect BLIK code was entered. Try again.', 'simpay');
        }

        if (in_array($errorCode, self::BANKING_APP_ERRORS, true)) {
            return __('Payment failed. Check the reason in the banking application and try again.', 'simpay');
        }

        if (in_array($errorCode, self::USER_DECLINED_ERRORS, true)) {
            return __('Payment rejected in a banking application. Try again.', 'simpay');
        }

        if (in_array($errorCode, self::TIMEOUT_ERRORS, true)) {
            return __('Payment failed - not confirmed on time in the banking application. Try again.', 'simpay');
        }

        if (in_array($errorCode, self::ALIAS_ERRORS, true)) {
            return __('Payment requires BLIK code.', 'simpay');
        }

        if (in_array($errorCode, self::AMOUNT_ERRORS, true)) {
            return __('Payment amount too high.', 'simpay');
        }

        if (in_array($errorCode, self::GENERAL_ERRORS, true)) {
            return __('Payment failed. Try again.', 'simpay');
        }

        // Fallback for unknown error codes
        return __('Payment failed. Try again.', 'simpay');
    }

    /**
     * Should the user be able to retry with a BLIK code after this error?
     */
    public static function canRetryWithCode(string $errorCode): bool
    {
        // Amount too high - no retry possible (full-screen failure)
        if (in_array($errorCode, self::AMOUNT_ERRORS, true)) {
            return false;
        }

        return true;
    }

    /**
     * Is this an error that requires falling back from OneClick to code entry?
     */
    public static function requiresCodeFallback(string $errorCode): bool
    {
        return in_array($errorCode, self::ALIAS_ERRORS, true);
    }
}


