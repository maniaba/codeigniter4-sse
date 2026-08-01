<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

interface SubscriptionEndpointInterface
{
    /**
     * @param list<string> $channels
     */
    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface;
}
