<?php

namespace SimPay\WooCommerce\IPN;

use SimPay\SDK\Exception\IpnException;
use SimPay\SDK\Exception\IpNotAllowedException;
use SimPay\WooCommerce\Settings\SimPayGlobalSettings;
use SimPay\WooCommerce\Factory\SimPayFactory;

class IPNController
{
    public function handle(): void
    {
        $serviceId    = (string) SimPayGlobalSettings::get('service_id', '');
        $signatureKey = (string) SimPayGlobalSettings::get('service_ipn_signature_key', '');

        if ($serviceId === '' || $signatureKey === '') {
            $this->error('Missing API configuration');
        }

        $payload = json_decode(@file_get_contents('php://input'), true);

        if (empty($payload)) {
            $this->error('Cannot read payload');
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $validateIp = (SimPayGlobalSettings::get('ipn_check_ip', '0') === '1');
        $remoteIp = $validateIp ? $this->getIp() : null;

        try {
            $ipn = SimPayFactory::sdk()->handleIpn($payload, $userAgent, $remoteIp);
        } catch (IpNotAllowedException $e) {
            $this->error('Invalid IP address: ' . $e->getIp());
        } catch (IpnException $e) {
            $this->error($e->getMessage());
        }

        $dispatcher = new IPNEventDispatcher(SimPayFactory::orders());
        $dispatcher->dispatch($ipn);

        header('Content-Type: text/plain', true, 200);
        echo 'OK';
        die();
    }

    private function error(string $message): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain', true, 400);
        }

        echo $message;
        die();
    }

    private function getIp(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return apply_filters('wpb_get_ip', $ip);
    }
}