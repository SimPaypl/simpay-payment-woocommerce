<?php

namespace SimPay\WooCommerce\Api;

use SimPay\SDK\Http\HttpClientInterface;
use SimPay\SDK\Http\HttpResponse;
use SimPay\SDK\Exception\HttpException;

final class WordPressHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], ?array $body = null, int $timeout = 30): HttpResponse
    {
        $args = [
            'method'  => strtoupper($method),
            'headers' => array_merge([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ], $headers),
            'timeout' => $timeout,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
            error_log('SimPay HTTP ' . strtoupper($method) . ' ' . $url . ' PAYLOAD: ' . $args['body']);
        }

        $res = wp_remote_request($url, $args);

        if (is_wp_error($res)) {
            throw new HttpException($res->get_error_message());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($res);
        $rawBody    = (string) wp_remote_retrieve_body($res);

        return new HttpResponse($statusCode, $rawBody);
    }
}