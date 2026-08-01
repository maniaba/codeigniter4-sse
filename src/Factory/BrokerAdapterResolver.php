<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Closure;
use LogicException;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;

final class BrokerAdapterResolver
{
    /**
     * @var array<string, BrokerAdapterInterface>
     */
    private array $shared = [];

    public function __construct(
        private readonly ?SerializerInterface $serializer = null,
        private readonly ?EventFactory $events = null,
        private readonly ?RedisConfigFactory $redisConfigs = null,
        private readonly ?MercureConfigFactory $mercureConfigs = null,
    ) {
    }

    public function resolve(Sse $config): BrokerAdapterInterface
    {
        $definition = $this->definition($config);
        $shared     = ($definition['shared'] ?? false) === true;

        if ($shared) {
            $cacheKey = spl_object_id($config) . ':' . $config->broker;

            if (! isset($this->shared[$cacheKey])) {
                $this->shared[$cacheKey] = $this->make($config, $definition);
            }

            return $this->shared[$cacheKey];
        }

        return $this->make($config, $definition);
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(Sse $config): array
    {
        $definition = $config->brokers[$config->broker] ?? null;

        if (! is_array($definition)) {
            throw new LogicException('The configured SSE broker definition must be an array.');
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function make(Sse $config, array $definition): BrokerAdapterInterface
    {
        if (array_key_exists('adapter', $definition)) {
            return $this->makeAdapter($config, $definition['adapter']);
        }

        if (array_key_exists('factory', $definition)) {
            return $this->makeFactory($definition['factory'])
                ->create($config, $this->context());
        }

        return (new LegacyBrokerAdapterFactory(
            $this->redisConfigs,
            $this->mercureConfigs,
        ))->create($config, $this->context());
    }

    private function makeAdapter(Sse $config, mixed $definition): BrokerAdapterInterface
    {
        if ($definition instanceof Closure) {
            $adapter = $definition($config, $this->context());

            if ($adapter instanceof BrokerAdapterInterface) {
                return $adapter;
            }
        }

        if (is_callable($definition) && ! is_string($definition)) {
            $adapter = $definition($config, $this->context());

            if ($adapter instanceof BrokerAdapterInterface) {
                return $adapter;
            }
        }

        if (is_string($definition)) {
            if (! class_exists($definition)) {
                throw new LogicException(sprintf('The configured SSE broker adapter "%s" does not exist.', $definition));
            }

            $adapter = new $definition();

            if ($adapter instanceof BrokerAdapterInterface) {
                return $adapter;
            }
        }

        if ($definition instanceof BrokerAdapterInterface) {
            return $definition;
        }

        throw new LogicException(
            'The configured SSE broker adapter must implement ' . BrokerAdapterInterface::class . '.',
        );
    }

    private function makeFactory(mixed $definition): BrokerAdapterFactoryInterface
    {
        if ($definition instanceof BrokerAdapterFactoryInterface) {
            return $definition;
        }

        if ($definition instanceof Closure) {
            $factory = $definition();

            if ($factory instanceof BrokerAdapterFactoryInterface) {
                return $factory;
            }
        }

        if (is_callable($definition) && ! is_string($definition)) {
            $factory = $definition();

            if ($factory instanceof BrokerAdapterFactoryInterface) {
                return $factory;
            }
        }

        if (is_string($definition)) {
            if (! class_exists($definition)) {
                throw new LogicException(sprintf('The configured SSE broker adapter factory "%s" does not exist.', $definition));
            }

            $factory = new $definition();

            if ($factory instanceof BrokerAdapterFactoryInterface) {
                return $factory;
            }
        }

        throw new LogicException(
            'The configured SSE broker factory must implement ' . BrokerAdapterFactoryInterface::class . '.',
        );
    }

    private function context(): BrokerBuildContext
    {
        return new BrokerBuildContext(
            $this->serializer ?? new JsonEventSerializer(),
            $this->events ?? new EventFactory(),
        );
    }
}
