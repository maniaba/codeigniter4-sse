<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

interface MercureHttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, scalar> $form
     */
    public function post(
        string $url,
        array $headers,
        array $form,
        float $connectTimeout,
        float $timeout,
        bool|string $verifyTls,
    ): MercureHttpResponse;
}
