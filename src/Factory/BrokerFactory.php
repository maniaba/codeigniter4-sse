<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Closure;
use LogicException;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureConfig;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Debug\Toolbar\TraceablePublisher;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;

final class BrokerFactory
{
    /**
     * @var array<string, object>
     */
    private static array $shared = [];

    public function __construct(
        private readonly ?SerializerInterface $serializer = null,
        private readonly ?RedisConfigFactory $redisConfigs = null,
        private readonly ?MercureConfigFactory $mercureConfigs = null,
        private readonly ?bool $enableToolbarTracing = null,
    ) {
    }

    public function publisher(Sse $config): PublisherInterface
    {
        $publisher = $this->broker($config, 'publisher');

        if (! $publisher instanceof PublisherInterface) {
            throw new LogicException('The configured SSE publisher must implement ' . PublisherInterface::class . '.');
        }

        return $this->tracePublisher($config, $publisher);
    }

    public function subscriber(Sse $config): SubscriberInterface
    {
        $subscriber = $this->broker($config, 'subscriber');

        if (! $subscriber instanceof SubscriberInterface) {
            throw new LogicException('The configured SSE subscriber must implement ' . SubscriberInterface::class . '.');
        }

        return $subscriber;
    }

    private function broker(Sse $config, string $role): object
    {
        $definition = $config->brokers[$config->broker] ?? null;

        if (! is_array($definition)) {
            throw new LogicException('The configured SSE broker definition must be an array.');
        }

        if (($definition['shared'] ?? false) === true) {
            $publisher  = $definition['publisher'] ?? null;
            $subscriber = $definition['subscriber'] ?? null;
            $sharedKey  = $publisher !== null && $publisher === $subscriber ? 'broker' : $role;
            $cacheKey   = spl_object_id($config) . ':' . $config->broker . ':' . $sharedKey;

            if (! isset(self::$shared[$cacheKey])) {
                self::$shared[$cacheKey] = $this->make($config, $definition[$role] ?? null, $role);
            }

            return self::$shared[$cacheKey];
        }

        return $this->make($config, $definition[$role] ?? null, $role);
    }

    private function make(Sse $config, mixed $definition, string $role): object
    {
        if ($definition instanceof Closure) {
            return $definition($config);
        }

        if (is_callable($definition) && ! is_string($definition)) {
            return $definition($config);
        }

        if (is_string($definition)) {
            return $this->makeClass($config, $definition);
        }

        throw new LogicException(sprintf('The SSE %s broker definition is invalid.', $role));
    }

    private function makeClass(Sse $config, string $class): object
    {
        if (! class_exists($class)) {
            throw new LogicException(sprintf('The configured SSE broker class "%s" does not exist.', $class));
        }

        if (is_a($class, RedisPublisher::class, true)) {
            return new $class(
                $this->redisConfig($config),
                $this->serializer(),
                $this->redisConnectionFactory($config),
            );
        }

        if (is_a($class, RedisSubscriber::class, true)) {
            return new $class(
                $this->redisConfig($config),
                $this->serializer(),
                $this->redisConnectionFactory($config),
            );
        }

        if (is_a($class, MercurePublisher::class, true)) {
            return new $class(
                $this->mercureConfig($config),
                $this->serializer(),
            );
        }

        return new $class();
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ?? new JsonEventSerializer();
    }

    private function redisConfig(Sse $config): RedisConfig
    {
        return ($this->redisConfigs ?? new RedisConfigFactory())->create($config);
    }

    private function mercureConfig(Sse $config): MercureConfig
    {
        return ($this->mercureConfigs ?? new MercureConfigFactory())->create($config);
    }

    private function redisConnectionFactory(Sse $config): RedisConnectionFactory
    {
        return new RedisConnectionFactory($this->redisConfig($config));
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
