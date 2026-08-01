<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\HealthCheckResult;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\HealthCheckableInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;

final readonly class MercureBrokerAdapter implements BrokerAdapterInterface, HealthCheckableInterface
{
    public function __construct(
        private MercureConfig $config,
        private PublisherInterface $publisher,
        private SubscriptionEndpointInterface $endpoint,
    ) {
    }

    public function publisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function subscriptionEndpoint(): SubscriptionEndpointInterface
    {
        return $this->endpoint;
    }

    public function healthCheck(): HealthCheckResult
    {
        if (! function_exists('curl_version')) {
            return HealthCheckResult::failed('The Mercure publisher requires the PHP cURL extension.');
        }

        return HealthCheckResult::ok(
            sprintf('Mercure SSE configuration is valid for %s.', $this->config->hubUrl),
            ['Hub readiness is exposed through the Mercure Caddy admin API and is not queried by this command.'],
        );
    }
}
