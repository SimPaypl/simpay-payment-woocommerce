<?php

namespace SimPay\WooCommerce\Blik;

use SimPay\SDK\BlikAlias;
use SimPay\SDK\Exception\ApiException;
use SimPay\WooCommerce\Database\BlikAliasRepository;
use SimPay\WooCommerce\Factory\SimPayFactory;
use SimPay\WooCommerce\Gateways\Blik;

/**
 * Handles BLIK Level 0 payment processing:
 * - Sending T6 code to SimPay API
 * - OneClick alias payments
 * - Status polling via AJAX
 * - IPN status updates
 */
final class BlikPaymentHandler
{
    /**
     * Process BLIK Level 0 payment (send code to API).
     *
     * Note: When OneClick is enabled on the service, the API ALWAYS requires
     * alias data alongside the BLIK code. We must send existing alias or register a new one.
     */
    public static function processBlikLevel0(\WC_Order $order, string $transactionId, string $blikCode, Blik $gateway, bool $skipAliasRegistration = false): array
    {
        $alias = null;

        // API requires alias data when OneClick is enabled on the service
        if ($gateway->is_blik_oneclick_enabled() && $order->get_customer_id() > 0) {
            $repository = new BlikAliasRepository();
            $customerId = (int) $order->get_customer_id();
            $aliasValue = (string) $customerId;
            $aliasLabel = $gateway->get_blik_alias_label();

            // When sending a BLIK code (ticket), alias must ALWAYS use value+type format.
            // UUID format is ONLY for OneClick payments (without code).
            $alias = BlikAlias::register($aliasLabel, $aliasValue);

            // Ensure alias record exists in DB for future IPN handling
            $existing = $repository->findByCustomerAndValue($customerId, $aliasValue);
            if ($existing === null) {
                $repository->upsert(
                    $customerId,
                    $aliasValue,
                    $aliasLabel,
                    'UID',
                    'alias_pending'
                );
            }
        }

        try {
            SimPayFactory::client()->sendBlikLevel0($transactionId, $blikCode, $alias);

            $order->update_meta_data('_simpay_blik_status', 'awaiting_confirmation');
            $order->add_order_note('SimPay BLIK: Code accepted, awaiting confirmation in banking app.');
            $order->save();

            return [
                'result' => 'success',
                'transaction_id' => $transactionId,
                'blik_status' => 'awaiting_confirmation',
            ];
        } catch (ApiException $e) {
            // If OneClick is not active on API side, retry without alias
            if ($alias !== null && $e->getHttpStatusCode() === 403) {
                try {
                    SimPayFactory::client()->sendBlikLevel0($transactionId, $blikCode, null);

                    $order->update_meta_data('_simpay_blik_status', 'awaiting_confirmation');
                    $order->add_order_note('SimPay BLIK: Code accepted (without alias), awaiting confirmation.');
                    $order->save();

                    return [
                        'result' => 'success',
                        'transaction_id' => $transactionId,
                        'blik_status' => 'awaiting_confirmation',
                    ];
                } catch (ApiException $retryException) {
                    $e = $retryException;
                }
            }

            $errorCode = self::extractBlikErrorCode($e);

            $order->update_meta_data('_simpay_blik_status', 'error');
            $order->update_meta_data('_simpay_blik_error_code', $errorCode);
            $order->save();

            return [
                'result' => 'failure',
                'blik_error' => $errorCode,
                'blik_message' => BlikErrorMessages::getMessage($errorCode),
            ];
        } catch (\Throwable $e) {
            $order->update_meta_data('_simpay_blik_status', 'error');
            $order->save();

            return [
                'result' => 'failure',
                'blik_error' => 'BLIK_GENERAL_ERROR',
                'blik_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process BLIK OneClick payment (pay without code using alias).
     */
    public static function processBlikOneClick(\WC_Order $order, string $transactionId, int $aliasDbId, Blik $gateway): array
    {
        $repository = new BlikAliasRepository();
        $aliasRow = $repository->findActiveByCustomerId((int) $order->get_customer_id());

        if ($aliasRow === null) {
            return [
                'result' => 'failure',
                'blik_error' => 'ALIAS_NOT_FOUND',
                'blik_message' => BlikErrorMessages::getMessage('ALIAS_NOT_FOUND'),
            ];
        }

        // Use UUID if available, otherwise use value+type
        if (!empty($aliasRow->alias_uuid)) {
            $alias = BlikAlias::fromUuid($aliasRow->alias_uuid, $aliasRow->alias_label);
        } elseif (!empty($aliasRow->alias_value)) {
            $alias = BlikAlias::fromValue($aliasRow->alias_value, $aliasRow->alias_label, $aliasRow->alias_type ?? 'UID');
        } else {
            error_log('SimPay BLIK OneClick: alias has no uuid and no value! Row: ' . json_encode($aliasRow));
            return [
                'result' => 'failure',
                'blik_error' => 'ALIAS_NOT_FOUND',
                'blik_message' => BlikErrorMessages::getMessage('ALIAS_NOT_FOUND'),
            ];
        }

        error_log('SimPay BLIK OneClick: sending alias payload: ' . json_encode($alias->toArray()));

        try {
            SimPayFactory::client()->sendBlikOneClick($transactionId, $alias);

            $order->update_meta_data('_simpay_blik_status', 'awaiting_confirmation');
            $order->add_order_note('SimPay BLIK OneClick: Push notification sent to banking app.');
            $order->save();

            return [
                'result' => 'success',
                'transaction_id' => $transactionId,
                'blik_status' => 'awaiting_confirmation',
            ];
        } catch (\SimPay\SDK\Exception\AliasNotUniqueException $e) {
            $order->update_meta_data('_simpay_blik_status', 'alias_not_unique');
            $order->save();

            return [
                'result' => 'failure',
                'blik_error' => 'ALIAS_NOT_UNIQUE',
                'blik_alternatives' => $e->getAlternatives(),
                'blik_message' => BlikErrorMessages::getMessage('ALIAS_NOT_UNIQUE'),
            ];
        } catch (ApiException $e) {
            $errorCode = self::extractBlikErrorCode($e);

            $order->update_meta_data('_simpay_blik_status', 'error');
            $order->update_meta_data('_simpay_blik_error_code', $errorCode);
            $order->save();

            return [
                'result' => 'failure',
                'blik_error' => $errorCode,
                'blik_message' => BlikErrorMessages::getMessage($errorCode),
            ];
        }
    }

    /**
     * Handle BLIK Level 0 code status IPN event.
     * Event type: transaction_blik_level0:code_status_changed
     */
    public static function handleBlikCodeStatus(\SimPay\SDK\IpnPayload $ipn): void
    {
        $ticketStatus = (string) ($ipn->data['ticket_status'] ?? '');
        $transactionData = $ipn->data['transaction'] ?? [];
        $control = (string) ($transactionData['control'] ?? '');

        if ($control === '') {
            return;
        }

        $orderId = (int) $control;
        $order = wc_get_order($orderId);

        if (!$order instanceof \WC_Order) {
            return;
        }

        $order->update_meta_data('_simpay_blik_ticket_status', $ticketStatus);

        if ($ticketStatus === 'VALID') {
            $order->update_meta_data('_simpay_blik_status', 'confirmed');
            $order->add_order_note('SimPay BLIK: Payment confirmed in banking app.');
        } else {
            $order->update_meta_data('_simpay_blik_status', 'rejected');
            $order->update_meta_data('_simpay_blik_error_code', $ticketStatus);
            $order->add_order_note(sprintf('SimPay BLIK: Payment rejected. Reason: %s', $ticketStatus));
        }

        $order->save();
    }

    /**
     * Handle BLIK alias status IPN event.
     * Event type: blik:alias_status_changed
     */
    public static function handleBlikAliasStatus(\SimPay\SDK\IpnPayload $ipn): void
    {
        $aliasUuid = $ipn->getAliasId();
        $aliasStatus = $ipn->getAliasStatus();

        if ($aliasUuid === null || $aliasStatus === null) {
            return;
        }

        $repository = new BlikAliasRepository();

        $existing = $repository->findByUuid($aliasUuid);
        if ($existing !== null) {
            $repository->updateStatusByUuid($aliasUuid, $aliasStatus);
            return;
        }

        $aliasValue = (string) ($ipn->data['value'] ?? '');
        if ($aliasValue === '') {
            return;
        }

        $customerId = (int) $aliasValue;
        if ($customerId <= 0) {
            return;
        }

        $existingByValue = $repository->findByCustomerAndValue($customerId, $aliasValue);
        if ($existingByValue !== null) {
            $repository->upsert(
                $customerId,
                $aliasValue,
                $existingByValue->alias_label,
                'UID',
                $aliasStatus,
                $aliasUuid
            );
        }
    }

    /**
     * Get current BLIK payment status for polling.
     */
    public static function getStatus(int $orderId): array
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof \WC_Order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }

        $blikStatus = (string) $order->get_meta('_simpay_blik_status');
        $orderStatus = $order->get_status();
        $errorCode = (string) $order->get_meta('_simpay_blik_error_code');

        if (in_array($orderStatus, ['processing', 'completed'], true)) {
            return [
                'status' => 'paid',
                'message' => __('Payment successful!', 'simpay'),
                'redirect' => $order->get_checkout_order_received_url(),
            ];
        }

        switch ($blikStatus) {
            case 'awaiting_confirmation':
                return [
                    'status' => 'pending',
                    'message' => __('Confirm the payment in your banking app', 'simpay'),
                ];

            case 'confirmed':
                return [
                    'status' => 'paid',
                    'message' => __('Payment successful!', 'simpay'),
                    'redirect' => $order->get_checkout_order_received_url(),
                ];

            case 'rejected':
            case 'error':
                return [
                    'status' => 'failed',
                    'error_code' => $errorCode,
                    'message' => $errorCode ? BlikErrorMessages::getMessage($errorCode) : __('Payment failed. Try again.', 'simpay'),
                    'can_retry' => BlikErrorMessages::canRetryWithCode($errorCode),
                ];

            default:
                return [
                    'status' => 'pending',
                    'message' => __('Processing payment...', 'simpay'),
                ];
        }
    }

    /**
     * Extract BLIK error code from ApiException.
     */
    private static function extractBlikErrorCode(ApiException $e): string
    {
        $code = $e->getApiCode();
        if ($code !== null && $code !== '') {
            return $code;
        }

        $message = $e->getApiMessage() ?? '';

        $knownCodes = [
            'INVALID_BLIK_CODE', 'PAYER_APP_NOT_ACTIVE', 'PAYER_APP_NOT_FOUND',
            'INVALID_BLIK_CODE_FORMAT', 'BLIK_CODE_EXPIRED', 'BLIK_CODE_LIMIT',
            'BLIK_CODE_CANCELLED', 'BLIK_CODE_NOT_SUPPORTED', 'BLIK_CODE_USED',
            'BLIK_GENERAL_ERROR', 'BLIK_TECHNICAL_BREAK',
            'INSUFFICIENT_FUNDS', 'LIMIT_EXCEEDED', 'TIMEOUT', 'GENERAL_ERROR',
            'SYSTEM_ERROR', 'SEC_DECLINED', 'USER_DECLINED', 'TAS_DECLINED',
            'ALIAS_DECLINED', 'ALIAS_NOT_FOUND', 'ALIAS_NOT_UNIQUE',
        ];

        foreach ($knownCodes as $knownCode) {
            if (stripos($message, $knownCode) !== false) {
                return $knownCode;
            }
        }

        if ($e->getHttpStatusCode() === 400) {
            return 'INVALID_BLIK_CODE';
        }

        return 'BLIK_GENERAL_ERROR';
    }
}

