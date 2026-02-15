<?php

namespace SimPay\WooCommerce\Api;

final class WordPressHttpClient
{
    public function request(
        string $method,
        string $url,
        array $body = [],
        array $headers = [],
        int $timeout = 15
    ): array {

        $args = [
            'method'  => strtoupper($method),
            'headers' => array_merge([
                'Accept' => 'application/json; charset=utf-8',
                'Content-Type' => 'application/json; charset=utf-8',
            ], $headers),
            'timeout' => $timeout,
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new \RuntimeException($res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP {$code}: {$raw}");
        }

        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, [], $headers);
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function delete(string $url, array $headers = []): array
    {
        return $this->request('DELETE', $url, [], $headers);
    }
}