<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\CURLRequest;
use LogicException;

final class CodeIgniterMercureHttpClient implements MercureHttpClientInterface
{
    private ?CURLRequest $client = null;

    public function post(
        string $url,
        array $headers,
        array $form,
        float $connectTimeout,
        float $timeout,
        bool|string $verifyTls,
    ): MercureHttpResponse {
        $client = $this->client ??= Services::curlrequest(
            ['fresh_connect' => false],
            getShared: false,
        );

        if (! $client instanceof CURLRequest) {
            throw new LogicException('The CodeIgniter CURLRequest service is not available.');
        }

        $response = $client->post($url, [
            'connect_timeout' => $connectTimeout,
            'timeout'         => $timeout,
            'verify'          => $verifyTls,
            'http_errors'     => false,
            'headers'         => $headers,
            'form_params'     => $form,
        ]);
        $body = $response->getBody();

        return new MercureHttpResponse(
            $response->getStatusCode(),
            is_string($body) ? $body : '',
        );
    }
}
