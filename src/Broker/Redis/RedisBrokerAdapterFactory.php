<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Endpoint\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Maniaba\CodeIgniterSse\Stream\SseConnectionOptions;

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
            $context->events,
            SseConnectionOptions::fromConfig($config),
        );

        return new RedisBrokerAdapter(
            $redis,
            $publisher,
            $subscriber,
            new LocalSseSubscriptionEndpoint(
                $manager,
                $config->requireAcceptHeader,
                channelSelectorValidator: new RedisChannelSelectorValidator($redis),
            ),
            new RedisHealthChecker($connectionFactory),
        );
    }
}
