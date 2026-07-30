<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use CodeIgniter\Config\BaseService;
use LogicException;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorization;
use Maniaba\CodeIgniterSse\Broker\InMemoryBroker;
use Maniaba\CodeIgniterSse\Broker\NullBroker;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactoryInterface;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisHealthChecker;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\HTTP\SseResponseFactory;
use Maniaba\CodeIgniterSse\Sse as SseManager;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;

class Services extends BaseService
{
    public static function sse(
        ?PublisherInterface $publisher = null,
        ?EventFactory $events = null,
        bool $getShared = true,
    ): SseManager {
        if ($getShared) {
            return self::shared('sse', SseManager::class, $publisher, $events);
        }

        return new SseManager(
            $publisher ?? self::resolved('ssePublisher', PublisherInterface::class),
            $events ?? self::resolved('sseEventFactory', EventFactory::class),
        );
    }

    public static function sseEventFactory(bool $getShared = true): EventFactory
    {
        if ($getShared) {
            return self::shared('sseEventFactory', EventFactory::class);
        }

        return new EventFactory();
    }

    public static function sseSerializer(bool $getShared = true): SerializerInterface
    {
        if ($getShared) {
            return self::shared('sseSerializer', SerializerInterface::class);
        }

        return new JsonEventSerializer();
    }

    public static function ssePublisher(
        ?Sse $config = null,
        bool $getShared = true,
    ): PublisherInterface {
        if ($getShared) {
            return self::shared('ssePublisher', PublisherInterface::class, $config);
        }

        $hasExplicitConfig = $config !== null;
        $config ??= Sse::discover();
        $config->validate();

        return match (strtolower($config->broker)) {
            'redis' => new RedisPublisher(
                static::sseRedisConfig($config, false),
                self::resolved('sseSerializer', SerializerInterface::class),
                $hasExplicitConfig
                    ? static::sseRedisConnectionFactory($config, false)
                    : self::resolved(
                        'sseRedisConnectionFactory',
                        RedisConnectionFactoryInterface::class,
                    ),
            ),
            'memory' => static::sseInMemoryBroker(),
            'null'   => static::sseNullBroker(),
            default  => throw new LogicException('Unsupported SSE broker: ' . $config->broker),
        };
    }

    public static function sseSubscriber(
        ?Sse $config = null,
        bool $getShared = true,
    ): SubscriberInterface {
        if ($getShared) {
            return self::shared('sseSubscriber', SubscriberInterface::class, $config);
        }

        $hasExplicitConfig = $config !== null;
        $config ??= Sse::discover();
        $config->validate();

        return match (strtolower($config->broker)) {
            'redis' => new RedisSubscriber(
                static::sseRedisConfig($config, false),
                self::resolved('sseSerializer', SerializerInterface::class),
                $hasExplicitConfig
                    ? static::sseRedisConnectionFactory($config, false)
                    : self::resolved(
                        'sseRedisConnectionFactory',
                        RedisConnectionFactoryInterface::class,
                    ),
            ),
            'memory' => static::sseInMemoryBroker(),
            'null'   => static::sseNullBroker(),
            default  => throw new LogicException('Unsupported SSE broker: ' . $config->broker),
        };
    }

    public static function sseInMemoryBroker(bool $getShared = true): InMemoryBroker
    {
        if ($getShared) {
            return self::shared('sseInMemoryBroker', InMemoryBroker::class);
        }

        return new InMemoryBroker();
    }

    public static function sseNullBroker(bool $getShared = true): NullBroker
    {
        if ($getShared) {
            return self::shared('sseNullBroker', NullBroker::class);
        }

        return new NullBroker();
    }

    public static function sseRedisConfig(
        ?Sse $config = null,
        bool $getShared = true,
    ): RedisConfig {
        if ($getShared) {
            return self::shared('sseRedisConfig', RedisConfig::class, $config);
        }

        $config ??= Sse::discover();
        $config->validate();

        return new RedisConfig(
            host: $config->redisHost,
            port: $config->redisPort,
            password: $config->redisPassword,
            database: $config->redisDatabase,
            connectTimeout: $config->redisConnectTimeout,
            readTimeout: $config->redisReadTimeout,
            channelPrefix: $config->channelPrefix,
            pollIntervalSeconds: $config->redisPollInterval,
            subscriberPingIntervalSeconds: $config->redisPingInterval,
            maxReconnectAttempts: $config->redisReconnectAttempts,
            reconnectDelayMilliseconds: $config->redisReconnectDelayMilliseconds,
            deduplicationCapacity: $config->redisDeduplicationCapacity,
            maxPayloadBytes: $config->redisMaxPayloadBytes,
            maxResponseElements: $config->redisMaxResponseElements,
            maxResponseDepth: $config->redisMaxResponseDepth,
            allowPatternSubscriptions: $config->allowPatternSubscriptions,
            username: $config->redisUsername,
            scheme: $config->redisScheme,
            streamContext: $config->redisStreamContext,
            clientName: $config->redisClientName,
        );
    }

    public static function sseRedisConnectionFactory(
        ?Sse $config = null,
        bool $getShared = true,
    ): RedisConnectionFactoryInterface {
        if ($getShared) {
            return self::shared(
                'sseRedisConnectionFactory',
                RedisConnectionFactoryInterface::class,
                $config,
            );
        }

        return new RedisConnectionFactory(static::sseRedisConfig($config, false));
    }

    public static function sseRedisHealthChecker(
        ?RedisConnectionFactoryInterface $factory = null,
        bool $getShared = true,
    ): RedisHealthChecker {
        if ($getShared) {
            return self::shared('sseRedisHealthChecker', RedisHealthChecker::class, $factory);
        }

        return new RedisHealthChecker(
            $factory ?? self::resolved(
                'sseRedisConnectionFactory',
                RedisConnectionFactoryInterface::class,
            ),
        );
    }

    public static function sseChannelAuthorizer(
        ?Sse $config = null,
        bool $getShared = true,
    ): ChannelAuthorizerInterface {
        if ($getShared) {
            return self::shared(
                'sseChannelAuthorizer',
                ChannelAuthorizerInterface::class,
                $config,
            );
        }

        $config ??= Sse::discover();
        $config->validate();
        $class      = $config->channelAuthorizer;
        $authorizer = new $class();

        if (! $authorizer instanceof ChannelAuthorizerInterface) {
            throw new LogicException($class . ' must implement ' . ChannelAuthorizerInterface::class . '.');
        }

        return $authorizer;
    }

    public static function sseUserResolver(
        ?Sse $config = null,
        bool $getShared = true,
    ): UserResolverInterface {
        if ($getShared) {
            return self::shared('sseUserResolver', UserResolverInterface::class, $config);
        }

        $config ??= Sse::discover();
        $config->validate();
        $class    = $config->userResolver;
        $resolver = new $class();

        if (! $resolver instanceof UserResolverInterface) {
            throw new LogicException($class . ' must implement ' . UserResolverInterface::class . '.');
        }

        return $resolver;
    }

    public static function sseChannelAuthorization(
        ?ChannelAuthorizerInterface $authorizer = null,
        bool $getShared = true,
    ): ChannelAuthorization {
        if ($getShared) {
            return self::shared(
                'sseChannelAuthorization',
                ChannelAuthorization::class,
                $authorizer,
            );
        }

        return new ChannelAuthorization(
            $authorizer ?? self::resolved(
                'sseChannelAuthorizer',
                ChannelAuthorizerInterface::class,
            ),
        );
    }

    public static function sseConnectionManager(
        ?SubscriberInterface $subscriber = null,
        ?SerializerInterface $serializer = null,
        ?EventFactory $events = null,
        ?Sse $config = null,
        bool $getShared = true,
    ): SseConnectionManager {
        if ($getShared) {
            return self::shared(
                'sseConnectionManager',
                SseConnectionManager::class,
                $subscriber,
                $serializer,
                $events,
                $config,
            );
        }

        $hasExplicitConfig = $config !== null;
        $config ??= Sse::discover();
        $config->validate();

        return new SseConnectionManager(
            $subscriber ?? (
                $hasExplicitConfig
                    ? static::sseSubscriber($config, false)
                    : self::resolved('sseSubscriber', SubscriberInterface::class)
            ),
            $serializer ?? self::resolved('sseSerializer', SerializerInterface::class),
            $events ?? self::resolved('sseEventFactory', EventFactory::class),
            $config->heartbeatInterval,
            $config->maxConnectionSeconds,
            $config->retryMilliseconds,
            $config->emitConnectedEvent,
        );
    }

    public static function sseResponseFactory(
        ?object $response = null,
        bool $getShared = true,
    ): SseResponseFactory {
        if ($getShared) {
            return self::shared('sseResponseFactory', SseResponseFactory::class, $response);
        }

        $response ??= service('response');

        if (! is_object($response)) {
            throw new LogicException('The CodeIgniter response service is unavailable.');
        }

        return new SseResponseFactory($response);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private static function shared(string $name, string $expected, mixed ...$arguments): object
    {
        $service = static::getSharedInstance($name, ...$arguments);

        if (! $service instanceof $expected) {
            throw new LogicException(
                sprintf('The shared service "%s" must be an instance of %s.', $name, $expected),
            );
        }

        return $service;
    }

    /**
     * Resolve a dependency through CodeIgniter's service discovery so an
     * application-level service override remains effective.
     *
     * @template T of object
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private static function resolved(string $name, string $expected): object
    {
        $service = service($name);

        if (! $service instanceof $expected) {
            throw new LogicException(
                sprintf('The service "%s" must be an instance of %s.', $name, $expected),
            );
        }

        return $service;
    }
}
