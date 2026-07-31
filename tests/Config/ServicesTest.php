<?php

declare(strict_types=1);

namespace Tests\Config;

use CodeIgniter\Config\Services as FrameworkServices;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Broker\InMemoryBroker;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Broker\NullBroker;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Config\Services;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Debug\Toolbar\SseEventHistory;
use Maniaba\CodeIgniterSse\Debug\Toolbar\TraceablePublisher;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Factory\AuthorizationFactory;
use Maniaba\CodeIgniterSse\Factory\BrokerFactory;
use Maniaba\CodeIgniterSse\Sse as SseManager;
use ReflectionProperty;
use Tests\Config\Fixtures\ConfiguredChannelAuthorizer;

/**
 * @internal
 */
final class ServicesTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        SseEventHistory::clear();
    }

    public function testConvenienceServiceIsAutoDiscovered(): void
    {
        $this->assertInstanceOf(SseManager::class, service('sse'));
    }

    public function testMemoryBrokerUsesTheSameSharedInstanceForBothSides(): void
    {
        $config         = new Sse();
        $config->broker = 'memory';

        $brokers    = new BrokerFactory();
        $publisher  = $brokers->publisher($config);
        $subscriber = $brokers->subscriber($config);

        $this->assertInstanceOf(InMemoryBroker::class, $publisher);
        $this->assertSame($publisher, $subscriber);
    }

    public function testRedisConfigMapsAllConnectionSettings(): void
    {
        $config        = new Sse();
        $config->redis = [
            'scheme'                     => 'tls',
            'host'                       => 'redis.internal',
            'port'                       => 6380,
            'username'                   => 'app',
            'password'                   => 'secret',
            'database'                   => 3,
            'deduplicationCapacity'      => 50,
            'reconnectAttempts'          => 4,
            'reconnectDelayMilliseconds' => 500,
            'pingInterval'               => 12.5,
            'maxPayloadBytes'            => 2048,
            'maxResponseElements'        => 128,
            'maxResponseDepth'           => 4,
            'clientName'                 => 'ci-sse',
        ];
        $config->channelPrefix             = 'test:sse:';
        $config->allowPatternSubscriptions = true;

        $publisher = Services::ssePublisher($config, false);
        $redis     = (new ReflectionProperty($publisher, 'config'))->getValue($publisher);

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
        $config        = new Sse();
        $config->redis = ['host' => 'explicit.redis.internal'];
        $publisher     = Services::ssePublisher($config, false);

        $this->assertInstanceOf(RedisPublisher::class, $publisher);

        $publisherConfig = (new ReflectionProperty($publisher, 'config'))->getValue($publisher);
        $factory         = (new ReflectionProperty($publisher, 'connectionFactory'))->getValue($publisher);

        $this->assertInstanceOf(RedisConfig::class, $publisherConfig);
        $this->assertSame('explicit.redis.internal', $publisherConfig->host);
        $this->assertInstanceOf(RedisConnectionFactory::class, $factory);

        $factoryConfig = (new ReflectionProperty($factory, 'config'))->getValue($factory);

        $this->assertInstanceOf(RedisConfig::class, $factoryConfig);
        $this->assertSame('explicit.redis.internal', $factoryConfig->host);
    }

    public function testMercurePublisherUsesTheConfiguredBrokerAdapter(): void
    {
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = [
            'publisherKey'  => 'publisher-test-secret',
            'subscriberKey' => 'subscriber-test-secret',
        ];

        $publisher = Services::ssePublisher($config, false);

        $this->assertInstanceOf(MercurePublisher::class, $publisher);
    }

    public function testCustomBrokerCanBeConfiguredWithFactories(): void
    {
        $publisher = new class () implements PublisherInterface {
            public function publish(string $channel, EventInterface $event): void
            {
            }
        };

        $subscriber = new class () implements SubscriberInterface {
            public function subscribe(
                array $channels,
                callable $onMessage,
                ?callable $shouldStop = null,
                ?callable $onIdle = null,
            ): void {
            }
        };

        $config                    = new Sse();
        $config->broker            = 'custom';
        $config->brokers['custom'] = [
            'publisher'  => static fn (): PublisherInterface => $publisher,
            'subscriber' => static fn (): SubscriberInterface => $subscriber,
        ];

        $brokers = new BrokerFactory();

        $this->assertSame($publisher, $brokers->publisher($config));
        $this->assertSame($subscriber, $brokers->subscriber($config));
    }

    public function testCustomBrokerCanUseSimpleClassNames(): void
    {
        $config                         = new Sse();
        $config->broker                 = 'custom-null';
        $config->brokers['custom-null'] = [
            'publisher'  => NullBroker::class,
            'subscriber' => NullBroker::class,
            'shared'     => true,
        ];

        $brokers = new BrokerFactory();

        $this->assertInstanceOf(NullBroker::class, $brokers->publisher($config));
        $this->assertSame(
            $brokers->publisher($config),
            $brokers->subscriber($config),
        );
    }

    public function testToolbarTracingCanTrackCustomBrokersCreatedByTheBrokerFactory(): void
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

        $subscriber = new class () implements SubscriberInterface {
            public function subscribe(
                array $channels,
                callable $onMessage,
                ?callable $shouldStop = null,
                ?callable $onIdle = null,
            ): void {
            }
        };

        $config                    = new Sse();
        $config->broker            = 'custom';
        $config->toolbar           = ['brokers' => ['custom']];
        $config->brokers['custom'] = [
            'publisher'  => static fn (): PublisherInterface => $publisher,
            'subscriber' => static fn (): SubscriberInterface => $subscriber,
        ];

        $traceable = (new BrokerFactory(enableToolbarTracing: true))->publisher($config);

        $this->assertInstanceOf(TraceablePublisher::class, $traceable);

        $traceable->publish('public.news', new SseEvent('news.created', ['id' => 42], 'event-1'));

        $history = SseEventHistory::all();

        $this->assertCount(1, $publisher->published);
        $this->assertCount(1, $history);
        $this->assertSame('custom', $config->broker);
        $this->assertSame('public.news', $history[0]['channel']);
        $this->assertSame('news.created', $history[0]['event']);
    }

    public function testToolbarTracingIgnoresBrokersThatAreNotConfiguredForTracing(): void
    {
        $config          = new Sse();
        $config->broker  = 'null';
        $config->toolbar = ['brokers' => ['redis']];

        $publisher = (new BrokerFactory(enableToolbarTracing: true))->publisher($config);

        $this->assertInstanceOf(NullBroker::class, $publisher);

        $publisher->publish('public.news', new SseEvent('news.created', ['id' => 42], 'event-1'));

        $this->assertSame([], SseEventHistory::all());
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

    public function testAuthorizationFactoryCanUseConfiguredClass(): void
    {
        ConfiguredChannelAuthorizer::$channels = [];

        $config                    = new Sse();
        $config->channelAuthorizer = ConfiguredChannelAuthorizer::class;

        $channels = (new AuthorizationFactory())->channelAuthorization($config)
            ->authorizeAll(null, ['users.42']);

        $this->assertSame(['users.42'], $channels);
        $this->assertSame(['users.42'], ConfiguredChannelAuthorizer::$channels);
    }
}
