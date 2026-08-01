<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Factory\RedisConfigFactory;
use Maniaba\CodeIgniterSse\HTTP\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;

final readonly class RedisBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function __construct(
        private ?RedisConfigFactory $configs = null,
    ) {
    }

    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        $redis             = ($this->configs ?? new RedisConfigFactory())->create($config);
        $connectionFactory = new RedisConnectionFactory($redis);
        $publisher         = new RedisPublisher($redis, $context->serializer, $connectionFactory);
        $subscriber        = new RedisSubscriber($redis, $context->serializer, $connectionFactory);
        $manager           = new SseConnectionManager(
            $subscriber,
            $context->serializer,
            $context->events,
            $config->heartbeatInterval,
            $config->maxConnectionSeconds,
            $config->retryMilliseconds,
            $config->emitConnectedEvent,
        );

        return new RedisBrokerAdapter(
            $redis,
            $publisher,
            $subscriber,
            new LocalSseSubscriptionEndpoint($manager, $config->requireAcceptHeader),
            new RedisHealthChecker(new RedisConnectionFactory($redis)),
        );
    }
}
