<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorProviderInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Factory\MercureSubscriptionFactory;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;

final readonly class MercureSubscriptionEndpoint implements SubscriptionEndpointInterface, ChannelSelectorValidatorProviderInterface
{
    public function __construct(
        private Sse $config,
        private ?MercureSubscriptionFactory $subscriptions = null,
        private ?MercureConfigFactory $configs = null,
    ) {
    }

    public function channelSelectorValidator(): ChannelSelectorValidatorInterface
    {
        return new ChannelNameValidator();
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
