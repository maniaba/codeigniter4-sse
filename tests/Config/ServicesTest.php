<?php

declare(strict_types=1);

namespace Tests\Config;

use CodeIgniter\Config\Services as FrameworkServices;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Broker\InMemoryBroker;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Config\Services;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Sse as SseManager;
use ReflectionProperty;

/**
 * @internal
 */
final class ServicesTest extends CIUnitTestCase
{
    public function testConvenienceServiceIsAutoDiscovered(): void
    {
        $this->assertInstanceOf(SseManager::class, service('sse'));
    }

    public function testMemoryBrokerUsesTheSameSharedInstanceForBothSides(): void
    {
        $config         = new Sse();
        $config->broker = 'memory';

        $publisher  = Services::ssePublisher($config, false);
        $subscriber = Services::sseSubscriber($config, false);

        $this->assertInstanceOf(InMemoryBroker::class, $publisher);
        $this->assertSame($publisher, $subscriber);
    }

    public function testRedisConfigMapsAllConnectionSettings(): void
    {
        $config                                  = new Sse();
        $config->redisScheme                     = 'tls';
        $config->redisHost                       = 'redis.internal';
        $config->redisPort                       = 6380;
        $config->redisUsername                   = 'app';
        $config->redisPassword                   = 'secret';
        $config->redisDatabase                   = 3;
        $config->channelPrefix                   = 'test:sse:';
        $config->allowPatternSubscriptions       = true;
        $config->redisDeduplicationCapacity      = 50;
        $config->redisReconnectAttempts          = 4;
        $config->redisReconnectDelayMilliseconds = 500;
        $config->redisPingInterval               = 12.5;
        $config->redisMaxPayloadBytes            = 2048;
        $config->redisMaxResponseElements        = 128;
        $config->redisMaxResponseDepth           = 4;
        $config->redisClientName                 = 'ci-sse';

        $redis = Services::sseRedisConfig($config, false);

        $this->assertInstanceOf(RedisConfig::class, $redis);
        $this->assertSame('tls://redis.internal:6380', $redis->endpoint());
        $this->assertSame('app', $redis->username);
        $this->assertSame('secret', $redis->password);
        $this->assertSame(3, $redis->database);
        $this->assertSame('test:sse:', $redis->channelPrefix);
        $this->assertTrue($redis->allowPatternSubscriptions);
        $this->assertSame(50, $redis->deduplicationCapacity);
        $this->assertSame(12.5, $redis->subscriberPingIntervalSeconds);
        $this->assertSame(2048, $redis->maxPayloadBytes);
        $this->assertSame(128, $redis->maxResponseElements);
        $this->assertSame(4, $redis->maxResponseDepth);
        $this->assertSame('ci-sse', $redis->clientName);
    }

    public function testExplicitRedisConfigDoesNotReuseAnExistingSharedConfig(): void
    {
        FrameworkServices::resetSingle('sseRedisConfig');
        Services::sseRedisConfig();

        try {
            $config            = new Sse();
            $config->redisHost = 'explicit.redis.internal';
            $publisher         = Services::ssePublisher($config, false);

            $this->assertInstanceOf(RedisPublisher::class, $publisher);

            $publisherConfig = (new ReflectionProperty($publisher, 'config'))->getValue($publisher);
            $factory         = (new ReflectionProperty($publisher, 'connectionFactory'))->getValue($publisher);

            $this->assertInstanceOf(RedisConfig::class, $publisherConfig);
            $this->assertSame('explicit.redis.internal', $publisherConfig->host);
            $this->assertInstanceOf(RedisConnectionFactory::class, $factory);

            $factoryConfig = (new ReflectionProperty($factory, 'config'))->getValue($factory);

            $this->assertInstanceOf(RedisConfig::class, $factoryConfig);
            $this->assertSame('explicit.redis.internal', $factoryConfig->host);
        } finally {
            FrameworkServices::resetSingle('sseRedisConfig');
        }
    }

    public function testConvenienceServiceUsesApplicationPublisherOverride(): void
    {
        $publisher = new class () implements PublisherInterface {
            /**
             * @var list<array{channel: string, event: EventInterface}>
             */
            public array $published = [];

            public function publish(string $channel, EventInterface $event): void
            {
                $this->published[] = ['channel' => $channel, 'event' => $event];
            }
        };

        FrameworkServices::injectMock('ssePublisher', $publisher);

        try {
            Services::sse(getShared: false)->publish('public.news', 'news.created', ['id' => 42]);

            $this->assertCount(1, $publisher->published);
            $this->assertSame('public.news', $publisher->published[0]['channel']);
            $this->assertSame('news.created', $publisher->published[0]['event']->name());
        } finally {
            FrameworkServices::resetSingle('ssePublisher');
        }
    }

    public function testAuthorizationServiceUsesApplicationAuthorizerOverride(): void
    {
        $authorizer = new class () implements ChannelAuthorizerInterface {
            /**
             * @var list<string>
             */
            public array $channels = [];

            public function authorize(?object $user, string $channel): bool
            {
                $this->channels[] = $channel;

                return true;
            }
        };

        FrameworkServices::injectMock('sseChannelAuthorizer', $authorizer);

        try {
            $channels = Services::sseChannelAuthorization(getShared: false)
                ->authorizeAll(null, ['users.42']);

            $this->assertSame(['users.42'], $channels);
            $this->assertSame(['users.42'], $authorizer->channels);
        } finally {
            FrameworkServices::resetSingle('sseChannelAuthorizer');
        }
    }
}
