<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Factory\MercureConfigFactory;
use Maniaba\CodeIgniterSse\Factory\MercureSubscriptionFactory;

final readonly class MercureSubscriptionEndpoint implements SubscriptionEndpointInterface
{
    public function __construct(
        private Sse $config,
        private ?MercureSubscriptionFactory $subscriptions = null,
        private ?MercureConfigFactory $configs = null,
    ) {
    }

    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface {
        $subscription = ($this->subscriptions ?? new MercureSubscriptionFactory())
            ->create($this->config, $channels);
        $mercure = ($this->configs ?? new MercureConfigFactory())->create($this->config);

        $response = $response
            ->setStatusCode(200)
            ->setJSON([
                'transport' => 'mercure',
                'hub'       => $subscription->hubUrl,
                'topics'    => $subscription->topics,
                'expiresAt' => $subscription->expiresAt,
            ])
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Link', sprintf('<%s>; rel="mercure"', $subscription->hubUrl))
            ->setHeader('X-Content-Type-Options', 'nosniff');

        if ($subscription->token !== null) {
            $response->setCookie(
                name: $mercure->cookieName,
                value: $subscription->token,
                expire: $mercure->subscriberTokenTtl,
                domain: $mercure->cookieDomain,
                path: $mercure->cookiePath,
                secure: $mercure->cookieSecure,
                httponly: $mercure->cookieHttpOnly,
                samesite: $mercure->cookieSameSite,
            );

            return $response;
        }

        $response->deleteCookie(
            $mercure->cookieName,
            $mercure->cookieDomain,
            $mercure->cookiePath,
        );

        return $response;
    }
}
