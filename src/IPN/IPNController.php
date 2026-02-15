<?php

namespace SimPay\WooCommerce\IPN;

use SimPay\WooCommerce\Settings\SimPayGlobalSettings;
use SimPay\WooCommerce\Factory\SimPayFactory;
use SimPay\WooCommerce\Service\IpAllowlistService;

class IPNController
{
    private string $serviceHash;
    private string $serviceId;
    private bool $validateIp;

    public function __construct(
    ) {
        $this->serviceHash = (string) SimPayGlobalSettings::get('service_ipn_signature_key', '');
        $this->serviceId = (string) SimPayGlobalSettings::get('service_id', '');
        $this->validateIp = (SimPayGlobalSettings::get('ipn_check_ip', '0') === '1');
    }

    public function handle()
    {

        if ($this->serviceHash === '' || $this->serviceId === '') {
            $this->error('Missing API configuration');
        }

        // Version check from UA: "SimPay-IPN/2.0"
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $parts = explode('/', $ua, 2);
        $version = $parts[1] ?? 'N/A';

        if ($version !== '2.0') {
            $this->error('IPN version is not supported (v: ' . $version . ')');
        }

        if ($this->validateIp) {
            $ipAllowlistService = new IpAllowlistService(SimPayFactory::api());
            if(!$ipAllowlistService->isAllowed($this->get_ip())) {
                $this->error('Invalid IP address: ' . $this->get_ip());
            }
        }

        $payload = json_decode(@file_get_contents('php://input'), true);

        if (empty($payload)) {
            $this->error('Cannot read payload');
        }

        if (
            empty($payload['type']) ||
            empty($payload['notification_id']) ||
            empty($payload['date']) ||
            empty($payload['data']) ||
            empty($payload['signature'])
        ) {
            $this->error('Invalid payload - missing required fields');
        }

        if (!$this->isValid($payload, $this->serviceHash)) {
            $this->error('Invalid signature');
        }

        $data = $payload['data'];
        if (isset($data['service_id']) && $data['service_id'] !== $this->serviceId) {
            $this->error('Invalid service_id');
        }

        $dispatcher = new IPNEventDispatcher(SimPayFactory::orders());
        $dispatcher->dispatch($payload);

        header('Content-Type: text/plain', true, 200);
        echo 'OK';
        die();
    }

    private function error(string $message)
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain', true, 400);
        }

        echo $message;
        die();
    }

    private function get_ip(): string
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

    private function isValid($payload, $ipnKey): bool
    {
        $data = $this->flattenArray($payload);
        $data[] = $ipnKey;

        $signature = hash('sha256', implode('|', $data));

        return hash_equals($signature, $payload['signature']);
    }

    private function flattenArray(array $array): array
    {
        unset($array['signature']);

        $return = [];

        array_walk_recursive($array, function ($a) use (&$return) {
            $return[] = $a;
        });

        return $return;
    }
}