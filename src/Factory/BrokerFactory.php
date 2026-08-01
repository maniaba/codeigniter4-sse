<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Debug\Toolbar\TraceablePublisher;
use Maniaba\CodeIgniterSse\Event\EventFactory;

final class BrokerFactory
{
    public function __construct(private readonly ?SerializerInterface $serializer = null, private readonly ?bool $enableToolbarTracing = null, private readonly ?EventFactory $events = null, private ?BrokerAdapterResolver $resolver = null)
    {
    }

    public function adapter(Sse $config): BrokerAdapterInterface
    {
        return $this->resolver()->resolve($config);
    }

    public function publisher(Sse $config): PublisherInterface
    {
        return $this->publisherFromAdapter($config, $this->adapter($config));
    }

    public function publisherFromAdapter(Sse $config, BrokerAdapterInterface $adapter): PublisherInterface
    {
        return $this->tracePublisher($config, $adapter->publisher());
    }

    public function subscriber(Sse $config): SubscriberInterface
    {
        $adapter = $this->adapter($config);

        if (! $adapter instanceof SubscriberAwareBrokerAdapterInterface) {
            throw new LogicException('The configured SSE broker does not provide a PHP subscriber.');
        }

        return $adapter->subscriber();
    }

    public function subscriptionEndpoint(Sse $config): SubscriptionEndpointInterface
    {
        return $this->adapter($config)->subscriptionEndpoint();
    }

    private function resolver(): BrokerAdapterResolver
    {
        if ($this->resolver === null) {
            $this->resolver = new BrokerAdapterResolver(
                $this->serializer,
                $this->events,
            );
        }

        return $this->resolver;
    }

    private function tracePublisher(Sse $config, PublisherInterface $publisher): PublisherInterface
    {
        $toolbar = $config->toolbar();

        if (
            ! $toolbar['enabled']
            || ! $this->toolbarTracingEnabled()
            || ! $this->toolbarTracksBroker($config->broker, $toolbar['brokers'])
        ) {
            return $publisher;
        }

        return new TraceablePublisher($publisher, $toolbar['maxEvents']);
    }

    private function toolbarTracingEnabled(): bool
    {
        if ($this->enableToolbarTracing !== null) {
            return $this->enableToolbarTracing;
        }

        return defined('CI_DEBUG') && constant('CI_DEBUG') === true && ! is_cli();
    }

    /**
     * @param list<string> $brokers
     */
    private function toolbarTracksBroker(string $broker, array $brokers): bool
    {
        return in_array('*', $brokers, true) || in_array($broker, $brokers, true);
    }
}
