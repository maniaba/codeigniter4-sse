<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\HealthCheckResult;
use Maniaba\CodeIgniterSse\Contracts\HealthCheckableInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;

final readonly class RedisBrokerAdapter implements SubscriberAwareBrokerAdapterInterface, HealthCheckableInterface
{
    public function __construct(
        private RedisConfig $config,
        private PublisherInterface $publisher,
        private SubscriberInterface $subscriber,
        private SubscriptionEndpointInterface $endpoint,
        private RedisHealthChecker $healthChecker,
    ) {
    }

    public function publisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function subscriber(): SubscriberInterface
    {
        return $this->subscriber;
    }

    public function subscriptionEndpoint(): SubscriptionEndpointInterface
    {
        return $this->endpoint;
    }

    public function healthCheck(): HealthCheckResult
    {
        if (! $this->healthChecker->check()) {
            return HealthCheckResult::failed(
                sprintf(
                    'Redis SSE health check failed for %s (database %d).',
                    $this->config->endpoint(),
                    $this->config->database,
                ),
                $this->healthChecker->lastError(),
            );
        }

        return HealthCheckResult::ok(
            sprintf('Redis SSE broker is reachable at %s.', $this->config->endpoint()),
        );
    }
}
