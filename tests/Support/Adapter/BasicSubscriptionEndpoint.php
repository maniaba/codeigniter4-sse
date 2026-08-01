<?php

declare(strict_types=1);

namespace Tests\Support\Adapter;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;

final class BasicSubscriptionEndpoint implements SubscriptionEndpointInterface
{
    /**
     * @var list<string>
     */
    public array $channels = [];

    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface {
        $this->channels = $channels;

        return $response->setStatusCode(204);
    }
}
