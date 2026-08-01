<?php

declare(strict_types=1);

namespace Tests\Broker;

use LogicException;
use Maniaba\CodeIgniterSse\Broker\InMemoryBroker;
use Maniaba\CodeIgniterSse\Broker\InMemoryBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\LocalBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\LocalBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\NullBroker;
use Maniaba\CodeIgniterSse\Broker\NullBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\HTTP\LocalSseSubscriptionEndpoint;
use PHPUnit\Framework\TestCase;
use Tests\Support\Adapter\BasicSubscriptionEndpoint;
use Tests\Support\Adapter\PublisherOnly;
use Tests\Support\Adapter\RecordingBroker;
use Tests\Support\RecordingPublisher;
use Tests\Support\RecordingSubscriber;

/**
 * @internal
 */
final class LocalBrokerAdapterTest extends TestCase
{
    public function testReturnsConfiguredLocalCollaborators(): void
    {
        $publisher  = new RecordingPublisher();
        $subscriber = new RecordingSubscriber();
        $endpoint   = new BasicSubscriptionEndpoint();
        $adapter    = new LocalBrokerAdapter($publisher, $subscriber, $endpoint);

        $this->assertSame($publisher, $adapter->publisher());
        $this->assertSame($subscriber, $adapter->subscriber());
        $this->assertSame($endpoint, $adapter->subscriptionEndpoint());
    }

    public function testLocalFactoryCreatesSharedPublisherSubscriberEndpoint(): void
    {
        RecordingBroker::reset();

        $adapter = (new LocalBrokerAdapterFactory(RecordingBroker::class))
            ->create(new Sse(), $this->context());

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $adapter);
        $this->assertInstanceOf(RecordingBroker::class, $adapter->publisher());
        $this->assertSame($adapter->publisher(), $adapter->subscriber());
        $this->assertInstanceOf(LocalSseSubscriptionEndpoint::class, $adapter->subscriptionEndpoint());
        $this->assertSame(1, RecordingBroker::$constructed);
    }

    public function testLocalFactoryRejectsMissingClass(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE broker class "MissingBroker" does not exist.');

        (new LocalBrokerAdapterFactory('MissingBroker'))->create(new Sse(), $this->context());
    }

    public function testLocalFactoryRejectsClassWithoutSubscriberSide(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must publish and subscribe');

        (new LocalBrokerAdapterFactory(PublisherOnly::class))->create(new Sse(), $this->context());
    }

    public function testBuiltInLocalFactoriesCreateExpectedBrokerTypes(): void
    {
        $context = $this->context();

        $memory = (new InMemoryBrokerAdapterFactory())->create(new Sse(), $context);
        $null   = (new NullBrokerAdapterFactory())->create(new Sse(), $context);

        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $memory);
        $this->assertInstanceOf(InMemoryBroker::class, $memory->publisher());
        $this->assertSame($memory->publisher(), $memory->subscriber());
        $this->assertInstanceOf(SubscriberAwareBrokerAdapterInterface::class, $null);
        $this->assertInstanceOf(NullBroker::class, $null->publisher());
        $this->assertSame($null->publisher(), $null->subscriber());
    }

    private function context(): BrokerBuildContext
    {
        return new BrokerBuildContext(new JsonEventSerializer(), new EventFactory());
    }
}
