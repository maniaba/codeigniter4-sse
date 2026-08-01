<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

interface PreflightSubscriptionEndpointInterface extends SubscriptionEndpointInterface
{
    public function preflight(RequestInterface $request, ResponseInterface $response): ?ResponseInterface;
}
