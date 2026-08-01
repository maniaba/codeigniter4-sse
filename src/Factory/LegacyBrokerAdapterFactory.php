<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Closure;
use LogicException;
use Maniaba\CodeIgniterSse\Broker\LocalBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\HTTP\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\HTTP\MercureSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;

final readonly class LegacyBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function __construct(
        private ?RedisConfigFactory $redisConfigs = null,
        private ?MercureConfigFactory $mercureConfigs = null,
    ) {
    }

    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        $definition = $config->brokers[$config->broker] ?? null;

        if (! is_array($definition)) {
            throw new LogicException('The configured SSE broker definition must be an array.');
        }

        $publisher = $this->make($config, $context, $definition['publisher'] ?? null, 'publisher');

        if (! $publisher instanceof PublisherInterface) {
            throw new LogicException('The configured SSE publisher must implement ' . PublisherInterface::class . '.');
        }

        if (($definition['transport'] ?? null) === 'mercure') {
            $configs = $this->mercureConfigs ?? new MercureConfigFactory();

            return new MercureBrokerAdapter(
                $configs->create($config),
                $publisher,
                new MercureSubscriptionEndpoint($config, configs: $configs),
            );
        }

        $subscriberDefinition = $definition['subscriber'] ?? null;
        $subscriber           = ($definition['shared'] ?? false) === true
            && ($definition['publisher'] ?? null) === $subscriberDefinition
            && $publisher instanceof SubscriberInterface
                ? $publisher
                : $this->make($config, $context, $subscriberDefinition, 'subscriber');

        if (! $subscriber instanceof SubscriberInterface) {
            throw new LogicException('The configured SSE subscriber must implement ' . SubscriberInterface::class . '.');
        }

        $manager = new SseConnectionManager(
            $subscriber,
            $context->serializer,
            $context->events,
            $config->heartbeatInterval,
            $config->maxConnectionSeconds,
            $config->retryMilliseconds,
            $config->emitConnectedEvent,
        );

        return new LocalBrokerAdapter(
            $publisher,
            $subscriber,
            new LocalSseSubscriptionEndpoint($manager, $config->requireAcceptHeader),
        );
    }

    private function make(Sse $config, BrokerBuildContext $context, mixed $definition, string $role): object
    {
        if ($definition instanceof Closure) {
            return $definition($config, $context);
        }

        if (is_callable($definition) && ! is_string($definition)) {
            return $definition($config, $context);
        }

        if (is_string($definition)) {
            return $this->makeClass($config, $context, $definition);
        }

        throw new LogicException(sprintf('The SSE %s broker definition is invalid.', $role));
    }

    private function makeClass(Sse $config, BrokerBuildContext $context, string $class): object
    {
        if (! class_exists($class)) {
            throw new LogicException(sprintf('The configured SSE broker class "%s" does not exist.', $class));
        }

        if (is_a($class, RedisPublisher::class, true)) {
            $redis = ($this->redisConfigs ?? new RedisConfigFactory())->create($config);

            return new $class(
                $redis,
                $context->serializer,
                new RedisConnectionFactory($redis),
            );
        }

        if (is_a($class, RedisSubscriber::class, true)) {
            $redis = ($this->redisConfigs ?? new RedisConfigFactory())->create($config);

            return new $class(
                $redis,
                $context->serializer,
                new RedisConnectionFactory($redis),
            );
        }

        if (is_a($class, MercurePublisher::class, true)) {
            return new $class(
                ($this->mercureConfigs ?? new MercureConfigFactory())->create($config),
                $context->serializer,
            );
        }

        return new $class();
    }
}
