<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

interface SubscriptionEndpointInterface
{
    /**
     * Browser bootstrap responses contain url (null reuses the request URL),
     * optional query parameters, and optional expiresAt. PHP stream endpoints
     * may instead return a streaming response for text/event-stream.
     *
     * @param list<string> $channels
     */
    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface;
}
