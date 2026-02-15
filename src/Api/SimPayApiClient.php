<?php

namespace SimPay\WooCommerce\Api;

class SimPayApiClient
{
    private ?WordPressHttpClient $http = null;
    private ?string $serviceId = null;
    private ?string $bearerToken = null;

    public function __construct(?WordPressHttpClient $http, ?string $serviceId, ?string $bearerToken)
    {
        $this->http = $http;
        $this->bearerToken = $bearerToken;
        $this->serviceId = $serviceId;
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->bearerToken,
        ];
    }

    public function createTransaction(array $payload): array
    {
        return $this->http->post(
            sprintf('https://api.simpay.pl/payment/%s/transactions', $this->serviceId),
            $payload,
            $this->authHeaders()
        );
    }

    /**
     * GET /ip
     * @return string[]
     */
    public function getAllowedIps(): array
    {
        $response = $this->http->get(
            'https://api.simpay.pl/ip',
            $this->authHeaders()
        );

        $ips = $response['data'] ?? [];

        if (!is_array($ips)) {
            return [];
        }

        return array_values(array_filter($ips, 'is_string'));
    }
}