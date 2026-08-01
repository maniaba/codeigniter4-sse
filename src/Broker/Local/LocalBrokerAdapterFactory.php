<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Local;

use LogicException;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Endpoint\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Maniaba\CodeIgniterSse\Stream\SseConnectionOptions;

final readonly class LocalBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function __construct(
        private string $brokerClass,
    ) {
    }

    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        if (! class_exists($this->brokerClass)) {
            throw new LogicException(sprintf('The configured SSE broker class "%s" does not exist.', $this->brokerClass));
        }

        $broker = new $this->brokerClass();

        if (! $broker instanceof PublisherInterface || ! $broker instanceof SubscriberInterface) {
            throw new LogicException(
                sprintf('The configured local SSE broker "%s" must publish and subscribe.', $this->brokerClass),
            );
        }

        $manager = new SseConnectionManager(
            $broker,
            $context->events,
            SseConnectionOptions::fromConfig($config),
        );

        return new LocalBrokerAdapter(
            $broker,
            $broker,
            new LocalSseSubscriptionEndpoint($manager, $config->requireAcceptHeader),
        );
    }
}
