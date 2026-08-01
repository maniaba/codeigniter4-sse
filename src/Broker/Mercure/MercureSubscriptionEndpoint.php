<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorProviderInterface;
use Maniaba\CodeIgniterSse\Contracts\PreflightSubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Factory\MercureSubscriptionFactory;
use Maniaba\CodeIgniterSse\HTTP\AcceptHeaderNegotiator;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;

final readonly class MercureSubscriptionEndpoint implements PreflightSubscriptionEndpointInterface, ChannelSelectorValidatorProviderInterface
{
    public function __construct(
        private Sse $config,
        private ?MercureSubscriptionFactory $subscriptions = null,
        private ?MercureConfigFactory $configs = null,
        private ?MercureConfig $mercure = null,
    ) {
    }

    public function channelSelectorValidator(): ChannelSelectorValidatorInterface
    {
        return new ChannelNameValidator();
    }

    public function preflight(RequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        $accept = $request->getHeaderLine('Accept');

        if (
            $accept === ''
            || (new AcceptHeaderNegotiator())->preferred(
                $accept,
                ['bootstrap' => 'application/json'],
            ) === 'bootstrap'
        ) {
            return null;
        }

        return $response
            ->setStatusCode(406)
            ->setJSON([
                'error' => [
                    'code'    => 'not_acceptable',
                    'message' => 'This endpoint requires Accept: application/json.',
                ],
            ])
            ->setHeader('Cache-Control', 'private, no-store')
            ->appendHeader('Vary', 'Accept')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface {
        $subscriptions = $this->subscriptions ?? new MercureSubscriptionFactory(mercure: $this->mercure);
        $subscription  = $subscriptions->create($this->config, $channels);
        $mercure       = $this->mercure ?? ($this->configs ?? new MercureConfigFactory())->create($this->config);

        $response = $response
            ->setStatusCode(200)
            ->setJSON([
                'hub'       => $subscription->hubUrl,
                'topics'    => $subscription->topics,
                'expiresAt' => $subscription->expiresAt,
            ])
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Link', sprintf('<%s>; rel="mercure"', $subscription->hubUrl))
            ->appendHeader('Vary', 'Accept')
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
