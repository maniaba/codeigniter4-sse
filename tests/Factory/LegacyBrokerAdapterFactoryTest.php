<?php

declare(strict_types=1);

namespace Tests\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Broker\LocalBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Factory\LegacyBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\HTTP\LocalSseSubscriptionEndpoint;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\Support\Adapter\RecordingBroker;
use Tests\Support\RecordingPublisher;
use Tests\Support\RecordingSubscriber;

/**
 * @internal
 */
final class LegacyBrokerAdapterFactoryTest extends TestCase
{
    public function testCreatesLocalAdapterFromLegacyPublisherSubscriberCallables(): void
    {
        $publisher  = new RecordingPublisher();
        $subscriber = new RecordingSubscriber();

        $adapter = (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => static fn (): PublisherInterface => $publisher,
                'subscriber' => static fn (): SubscriberInterface => $subscriber,
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $adapter);
        $this->assertInstanceOf(LocalBrokerAdapter::class, $adapter);
        $this->assertSame($publisher, $adapter->publisher());
        $this->assertSame($subscriber, $adapter->subscriber());
        $this->assertInstanceOf(LocalSseSubscriptionEndpoint::class, $adapter->subscriptionEndpoint());
    }

    public function testCreatesLocalAdapterFromLegacyInvokableDefinitions(): void
    {
        $publisher  = new RecordingPublisher();
        $subscriber = new RecordingSubscriber();

        $adapter = (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher' => new class ($publisher) {
                    public function __construct(
                        private readonly PublisherInterface $publisher,
                    ) {
                    }

                    public function __invoke(): PublisherInterface
                    {
                        return $this->publisher;
                    }
                },
                'subscriber' => new class ($subscriber) {
                    public function __construct(
                        private readonly SubscriberInterface $subscriber,
                    ) {
                    }

                    public function __invoke(): SubscriberInterface
                    {
                        return $this->subscriber;
                    }
                },
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $adapter);
        $this->assertSame($publisher, $adapter->publisher());
        $this->assertSame($subscriber, $adapter->subscriber());
    }

    public function testLegacySharedDefinitionReusesSingleBrokerObject(): void
    {
        RecordingBroker::reset();

        $adapter = (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => RecordingBroker::class,
                'subscriber' => RecordingBroker::class,
                'shared'     => true,
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $adapter);
        $this->assertSame($adapter->publisher(), $adapter->subscriber());
        $this->assertSame(1, RecordingBroker::$constructed);
    }

    public function testLegacyNonSharedDefinitionCreatesPublisherAndSubscriberSeparately(): void
    {
        RecordingBroker::reset();

        $adapter = (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => RecordingBroker::class,
                'subscriber' => RecordingBroker::class,
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $adapter);
        $this->assertNotSame($adapter->publisher(), $adapter->subscriber());
        $this->assertSame(2, RecordingBroker::$constructed);
    }

    public function testCreatesMercureAdapterFromLegacyTransportDefinition(): void
    {
        $adapter = (new LegacyBrokerAdapterFactory())->create(
            $this->mercureConfig([
                'publisher' => MercurePublisher::class,
                'transport' => 'mercure',
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(MercureBrokerAdapter::class, $adapter);
        $this->assertInstanceOf(MercurePublisher::class, $adapter->publisher());
    }

    public function testCreatesLegacyRedisPublisherAndSubscriber(): void
    {
        $adapter = (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => RedisPublisher::class,
                'subscriber' => RedisSubscriber::class,
            ]),
            $this->context(),
        );

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $adapter);
        $this->assertInstanceOf(RedisPublisher::class, $adapter->publisher());
        $this->assertInstanceOf(RedisSubscriber::class, $adapter->subscriber());
    }

    public function testRejectsNonArrayDefinition(): void
    {
        $config         = new Sse();
        $config->broker = 'legacy';
        (new ReflectionProperty($config, 'brokers'))->setValue($config, ['legacy' => 'invalid']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE broker definition must be an array.');

        (new LegacyBrokerAdapterFactory())->create($config, $this->context());
    }

    public function testRejectsInvalidPublisherDefinition(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE publisher must implement ' . PublisherInterface::class);

        (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => static fn (): stdClass => new stdClass(),
                'subscriber' => static fn (): SubscriberInterface => new RecordingSubscriber(),
            ]),
            $this->context(),
        );
    }

    public function testRejectsInvalidSubscriberDefinition(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE subscriber must implement ' . SubscriberInterface::class);

        (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => static fn (): PublisherInterface => new RecordingPublisher(),
                'subscriber' => static fn (): stdClass => new stdClass(),
            ]),
            $this->context(),
        );
    }

    public function testRejectsMissingBrokerClass(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE broker class "MissingLegacyBroker" does not exist.');

        (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher'  => 'MissingLegacyBroker',
                'subscriber' => static fn (): SubscriberInterface => new RecordingSubscriber(),
            ]),
            $this->context(),
        );
    }

    public function testRejectsMissingSubscriberDefinition(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The SSE subscriber broker definition is invalid.');

        (new LegacyBrokerAdapterFactory())->create(
            $this->config([
                'publisher' => static fn (): PublisherInterface => new RecordingPublisher(),
            ]),
            $this->context(),
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function config(array $definition): Sse
    {
        $config                    = new Sse();
        $config->broker            = 'legacy';
        $config->brokers['legacy'] = $definition;

        return $config;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function mercureConfig(array $definition): Sse
    {
        $config          = $this->config($definition);
        $config->mercure = [
            'hubUrl'        => 'http://mercure/.well-known/mercure',
            'publicHubUrl'  => 'https://example.test/.well-known/mercure',
            'publisherKey'  => 'publisher-test-secret',
            'subscriberKey' => 'subscriber-test-secret',
        ];

        return $config;
    }

    private function context(): BrokerBuildContext
    {
        return new BrokerBuildContext(new JsonEventSerializer(), new EventFactory());
    }
}
