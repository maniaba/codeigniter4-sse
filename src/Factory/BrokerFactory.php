<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Closure;
use LogicException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
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
    ) {
    }

    public function publisher(Sse $config): PublisherInterface
    {
        $publisher = $this->broker($config, 'publisher');

        if (! $publisher instanceof PublisherInterface) {
            throw new LogicException('The configured SSE publisher must implement ' . PublisherInterface::class . '.');
        }

        return $publisher;
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
            $sharedKey = $definition['publisher'] === $definition['subscriber'] ? 'broker' : $role;
            $cacheKey  = spl_object_id($config) . ':' . $config->broker . ':' . $sharedKey;

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

    private function redisConnectionFactory(Sse $config): RedisConnectionFactory
    {
        return new RedisConnectionFactory($this->redisConfig($config));
    }
}
