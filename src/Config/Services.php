<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use Closure;
use CodeIgniter\Config\BaseService;
use LogicException;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorization;
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
    /**
     * @var array<string, object>
     */
    private static array $brokerInstances = [];

    public static function sse(
        ?PublisherInterface $publisher = null,
        ?EventFactory $events = null,
        bool $getShared = true,
    ): SseManager {
        if ($getShared) {
            $service = static::getSharedInstance('sse', $publisher, $events);

            if (! $service instanceof SseManager) {
                throw new LogicException('The shared sse service must be an instance of ' . SseManager::class . '.');
            }

            return $service;
        }

        $publisher ??= service('ssePublisher');
        $events ??= service('sseEventFactory');

        if (! $publisher instanceof PublisherInterface) {
            throw new LogicException('The ssePublisher service must implement ' . PublisherInterface::class . '.');
        }

        if (! $events instanceof EventFactory) {
            throw new LogicException('The sseEventFactory service must be an instance of ' . EventFactory::class . '.');
        }

        return new SseManager($publisher, $events);
    }

    public static function sseEventFactory(bool $getShared = true): EventFactory
    {
        if ($getShared) {
            $service = static::getSharedInstance('sseEventFactory');

            if (! $service instanceof EventFactory) {
                throw new LogicException('The shared sseEventFactory service must be an instance of ' . EventFactory::class . '.');
            }

            return $service;
        }

        return new EventFactory();
    }

    public static function sseSerializer(bool $getShared = true): SerializerInterface
    {
        if ($getShared) {
            $service = static::getSharedInstance('sseSerializer');

            if (! $service instanceof SerializerInterface) {
                throw new LogicException('The shared sseSerializer service must implement ' . SerializerInterface::class . '.');
            }

            return $service;
        }

        return new JsonEventSerializer();
    }

    public static function ssePublisher(
        ?Sse $config = null,
        bool $getShared = true,
    ): PublisherInterface {
        if ($getShared) {
            $service = static::getSharedInstance('ssePublisher', $config);

            if (! $service instanceof PublisherInterface) {
                throw new LogicException('The shared ssePublisher service must implement ' . PublisherInterface::class . '.');
            }

            return $service;
        }

        $config ??= Sse::discover();
        $config->validate();

        $publisher = self::broker($config, 'publisher');

        if (! $publisher instanceof PublisherInterface) {
            throw new LogicException('The configured SSE publisher must implement ' . PublisherInterface::class . '.');
        }

        return $publisher;
    }

    public static function sseSubscriber(
        ?Sse $config = null,
        bool $getShared = true,
    ): SubscriberInterface {
        if ($getShared) {
            $service = static::getSharedInstance('sseSubscriber', $config);

            if (! $service instanceof SubscriberInterface) {
                throw new LogicException('The shared sseSubscriber service must implement ' . SubscriberInterface::class . '.');
            }

            return $service;
        }

        $config ??= Sse::discover();
        $config->validate();

        $subscriber = self::broker($config, 'subscriber');

        if (! $subscriber instanceof SubscriberInterface) {
            throw new LogicException('The configured SSE subscriber must implement ' . SubscriberInterface::class . '.');
        }

        return $subscriber;
    }

    public static function sseRedisHealthChecker(
        ?RedisConnectionFactoryInterface $factory = null,
        ?Sse $config = null,
        bool $getShared = true,
    ): RedisHealthChecker {
        if ($getShared) {
            $service = static::getSharedInstance('sseRedisHealthChecker', $factory, $config);

            if (! $service instanceof RedisHealthChecker) {
                throw new LogicException('The shared sseRedisHealthChecker service must be an instance of ' . RedisHealthChecker::class . '.');
            }

            return $service;
        }

        $config ??= Sse::discover();

        return new RedisHealthChecker($factory ?? self::redisConnectionFactory($config));
    }

    public static function sseChannelAuthorizer(
        ?Sse $config = null,
        bool $getShared = true,
    ): ChannelAuthorizerInterface {
        if ($getShared) {
            $service = static::getSharedInstance('sseChannelAuthorizer', $config);

            if (! $service instanceof ChannelAuthorizerInterface) {
                throw new LogicException('The shared sseChannelAuthorizer service must implement ' . ChannelAuthorizerInterface::class . '.');
            }

            return $service;
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
            $service = static::getSharedInstance('sseUserResolver', $config);

            if (! $service instanceof UserResolverInterface) {
                throw new LogicException('The shared sseUserResolver service must implement ' . UserResolverInterface::class . '.');
            }

            return $service;
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
            $service = static::getSharedInstance('sseChannelAuthorization', $authorizer);

            if (! $service instanceof ChannelAuthorization) {
                throw new LogicException('The shared sseChannelAuthorization service must be an instance of ' . ChannelAuthorization::class . '.');
            }

            return $service;
        }

        $authorizer ??= service('sseChannelAuthorizer');

        if (! $authorizer instanceof ChannelAuthorizerInterface) {
            throw new LogicException('The sseChannelAuthorizer service must implement ' . ChannelAuthorizerInterface::class . '.');
        }

        return new ChannelAuthorization($authorizer);
    }

    public static function sseConnectionManager(
        ?SubscriberInterface $subscriber = null,
        ?SerializerInterface $serializer = null,
        ?EventFactory $events = null,
        ?Sse $config = null,
        bool $getShared = true,
    ): SseConnectionManager {
        if ($getShared) {
            $service = static::getSharedInstance(
                'sseConnectionManager',
                $subscriber,
                $serializer,
                $events,
                $config,
            );

            if (! $service instanceof SseConnectionManager) {
                throw new LogicException('The shared sseConnectionManager service must be an instance of ' . SseConnectionManager::class . '.');
            }

            return $service;
        }

        $hasConfig = $config instanceof Sse;
        $config ??= Sse::discover();
        $config->validate();

        $subscriber ??= $hasConfig ? static::sseSubscriber($config, false) : service('sseSubscriber');
        $serializer ??= service('sseSerializer');
        $events ??= service('sseEventFactory');

        if (! $subscriber instanceof SubscriberInterface) {
            throw new LogicException('The sseSubscriber service must implement ' . SubscriberInterface::class . '.');
        }

        if (! $serializer instanceof SerializerInterface) {
            throw new LogicException('The sseSerializer service must implement ' . SerializerInterface::class . '.');
        }

        if (! $events instanceof EventFactory) {
            throw new LogicException('The sseEventFactory service must be an instance of ' . EventFactory::class . '.');
        }

        return new SseConnectionManager(
            $subscriber,
            $serializer,
            $events,
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
            $service = static::getSharedInstance('sseResponseFactory', $response);

            if (! $service instanceof SseResponseFactory) {
                throw new LogicException('The shared sseResponseFactory service must be an instance of ' . SseResponseFactory::class . '.');
            }

            return $service;
        }

        $response ??= service('response');

        if (! is_object($response)) {
            throw new LogicException('The CodeIgniter response service is unavailable.');
        }

        return new SseResponseFactory($response);
    }

    private static function broker(Sse $config, string $role): object
    {
        $definition = $config->brokers[$config->broker] ?? null;

        if (! is_array($definition)) {
            throw new LogicException('The configured SSE broker definition must be an array.');
        }

        if (($definition['shared'] ?? false) === true) {
            $sharedKey = $definition['publisher'] === $definition['subscriber'] ? 'broker' : $role;
            $cacheKey  = spl_object_id($config) . ':' . $config->broker . ':' . $sharedKey;

            if (! isset(self::$brokerInstances[$cacheKey])) {
                self::$brokerInstances[$cacheKey] = self::makeBroker($config, $definition[$role] ?? null, $role);
            }

            return self::$brokerInstances[$cacheKey];
        }

        return self::makeBroker($config, $definition[$role] ?? null, $role);
    }

    private static function makeBroker(Sse $config, mixed $definition, string $role): object
    {
        if ($definition instanceof Closure) {
            return $definition($config);
        }

        if (is_callable($definition) && ! is_string($definition)) {
            return $definition($config);
        }

        if (is_string($definition)) {
            return self::makeBrokerClass($config, $definition);
        }

        throw new LogicException(sprintf('The SSE %s broker definition is invalid.', $role));
    }

    private static function makeBrokerClass(Sse $config, string $class): object
    {
        if (! class_exists($class)) {
            throw new LogicException(sprintf('The configured SSE broker class "%s" does not exist.', $class));
        }

        if (is_a($class, RedisPublisher::class, true)) {
            return new $class(
                self::redisConfig($config),
                self::serializer(),
                self::redisConnectionFactory($config),
            );
        }

        if (is_a($class, RedisSubscriber::class, true)) {
            return new $class(
                self::redisConfig($config),
                self::serializer(),
                self::redisConnectionFactory($config),
            );
        }

        return new $class();
    }

    private static function serializer(): SerializerInterface
    {
        $serializer = service('sseSerializer');

        if (! $serializer instanceof SerializerInterface) {
            throw new LogicException('The sseSerializer service must implement ' . SerializerInterface::class . '.');
        }

        return $serializer;
    }

    private static function redisConnectionFactory(Sse $config): RedisConnectionFactoryInterface
    {
        return new RedisConnectionFactory(self::redisConfig($config));
    }

    private static function redisConfig(Sse $config): RedisConfig
    {
        $redis = $config->redis();

        return new RedisConfig(
            host: (string) $redis['host'],
            port: (int) $redis['port'],
            password: self::nullableString($redis['password'] ?? null),
            database: (int) $redis['database'],
            connectTimeout: (float) $redis['connectTimeout'],
            readTimeout: (float) $redis['readTimeout'],
            channelPrefix: $config->channelPrefix,
            pollIntervalSeconds: (float) $redis['pollInterval'],
            subscriberPingIntervalSeconds: (float) $redis['pingInterval'],
            maxReconnectAttempts: (int) $redis['reconnectAttempts'],
            reconnectDelayMilliseconds: (int) $redis['reconnectDelayMilliseconds'],
            deduplicationCapacity: (int) $redis['deduplicationCapacity'],
            maxPayloadBytes: (int) $redis['maxPayloadBytes'],
            maxResponseElements: (int) $redis['maxResponseElements'],
            maxResponseDepth: (int) $redis['maxResponseDepth'],
            allowPatternSubscriptions: $config->allowPatternSubscriptions,
            username: self::nullableString($redis['username'] ?? null),
            scheme: (string) $redis['scheme'],
            streamContext: is_array($redis['streamContext']) ? $redis['streamContext'] : [],
            clientName: self::nullableString($redis['clientName'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
