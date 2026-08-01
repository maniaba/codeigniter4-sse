<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\BoundedEventIdSet;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisSubscriptionException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriptionMessage;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Support\Tests\Broker\Redis\Fixtures\FakeRedisConnection;
use Support\Tests\Broker\Redis\Fixtures\FakeRedisConnectionFactory;

/**
 * @internal
 */
final class RedisSubscriberTest extends TestCase
{
    private JsonEventSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonEventSerializer();
    }

    public function testSubscribesToPrefixedChannelsAndPatternsAndInvokesIdleCallback(): void
    {
        $connection = new FakeRedisConnection();
        $factory    = new FakeRedisConnectionFactory([$connection]);
        $subscriber = new RedisSubscriber(
            new RedisConfig(
                channelPrefix: 'project:sse:',
                allowPatternSubscriptions: true,
                reconnectDelayMilliseconds: 0,
            ),
            $this->serializer,
            $factory,
        );
        $idleCalls = 0;

        $subscriber->subscribe(
            ['public.news', 'public.news', 'projects.*.activity'],
            static function (): void {
                self::fail('No message should have been delivered.');
            },
            static fn (): bool => $connection->readCalls >= 1,
            static function () use (&$idleCalls): void {
                $idleCalls++;
            },
        );

        $this->assertSame(['project:sse:public.news'], $connection->subscribedChannels);
        $this->assertSame(['project:sse:projects.*.activity'], $connection->subscribedPatterns);
        $this->assertSame(1, $idleCalls);
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testSkipsInvalidAndMismatchedPayloadsThenDeliversValidMessage(): void
    {
        $connection           = new FakeRedisConnection();
        $connection->messages = [
            new RedisSubscriptionMessage('app:sse:users.42', '{invalid'),
            new RedisSubscriptionMessage(
                'app:sse:users.42',
                $this->payload('users.7', 'wrong-channel'),
            ),
            new RedisSubscriptionMessage(
                'app:sse:users.42',
                $this->payload('users.42', 'valid'),
            ),
        ];
        $subscriber = $this->subscriber([$connection]);
        $received   = [];

        $subscriber->subscribe(
            ['users.42'],
            static function ($message) use (&$received): void {
                $received[] = $message->id();
            },
            static fn (): bool => $connection->readCalls >= 3,
        );

        $this->assertSame(['valid'], $received);
        $this->assertSame(3, $connection->readCalls);
    }

    public function testDeduplicatesOverlappingExactAndPatternDeliveriesByEventId(): void
    {
        $connection           = new FakeRedisConnection();
        $payload              = $this->payload('public.news', 'same-id');
        $connection->messages = [
            new RedisSubscriptionMessage('app:sse:public.news', $payload),
            new RedisSubscriptionMessage('app:sse:public.news', $payload, 'app:sse:public.*'),
        ];
        $subscriber = new RedisSubscriber(
            new RedisConfig(allowPatternSubscriptions: true, reconnectDelayMilliseconds: 0),
            $this->serializer,
            new FakeRedisConnectionFactory([$connection]),
        );
        $received = [];

        $subscriber->subscribe(
            ['public.news', 'public.*'],
            static function ($message) use (&$received): void {
                $received[] = $message->id();
            },
            static fn (): bool => $connection->readCalls >= 2,
        );

        $this->assertSame(['same-id'], $received);
    }

    public function testReconnectsWithASeparateConnection(): void
    {
        $first            = new FakeRedisConnection();
        $first->messages  = [new RedisConnectionException('socket closed')];
        $second           = new FakeRedisConnection();
        $second->messages = [
            new RedisSubscriptionMessage('app:sse:public.news', $this->payload('public.news', 'after-reconnect')),
        ];
        $factory    = new FakeRedisConnectionFactory([$first, $second]);
        $subscriber = new RedisSubscriber(
            new RedisConfig(maxReconnectAttempts: 1, reconnectDelayMilliseconds: 0),
            $this->serializer,
            $factory,
        );
        $received = [];

        $subscriber->subscribe(
            ['public.news'],
            static function ($message) use (&$received): void {
                $received[] = $message->id();
            },
            static fn (): bool => $second->readCalls >= 1,
        );

        $this->assertSame(['after-reconnect'], $received);
        $this->assertSame(2, $factory->createCalls);
        $this->assertSame(1, $first->closeCalls);
        $this->assertSame(1, $second->closeCalls);
    }

    public function testThrowsAfterReconnectBudgetIsExhausted(): void
    {
        $first                  = new FakeRedisConnection();
        $first->connectFailure  = new RedisConnectionException('offline');
        $second                 = new FakeRedisConnection();
        $second->connectFailure = new RedisConnectionException('still offline');
        $subscriber             = $this->subscriber([$first, $second], maxReconnectAttempts: 1);

        $this->expectException(RedisSubscriptionException::class);

        $subscriber->subscribe(['public.news'], static function (): void {
        });
    }

    public function testRejectsPatternsWhenTheyAreDisabled(): void
    {
        $subscriber = $this->subscriber([new FakeRedisConnection()]);

        $this->expectException(InvalidChannelException::class);

        $subscriber->subscribe(['public.*'], static function (): void {
        });
    }

    public function testDoesNotWrapApplicationCallbackExceptions(): void
    {
        $connection           = new FakeRedisConnection();
        $connection->messages = [
            new RedisSubscriptionMessage('app:sse:public.news', $this->payload('public.news', 'one')),
        ];
        $subscriber = $this->subscriber([$connection]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handler failed');

        $subscriber->subscribe(
            ['public.news'],
            static function (): never {
                throw new RuntimeException('handler failed');
            },
        );
    }

    public function testPingsAnIdleSubscriptionToDetectHalfOpenConnections(): void
    {
        $connection = new FakeRedisConnection();
        $times      = [0.0, 16.0];
        $subscriber = new RedisSubscriber(
            new RedisConfig(
                subscriberPingIntervalSeconds: 15.0,
                reconnectDelayMilliseconds: 0,
            ),
            $this->serializer,
            new FakeRedisConnectionFactory([$connection]),
            static function () use (&$times): float {
                return array_shift($times) ?? 16.0;
            },
        );

        $subscriber->subscribe(
            ['public.news'],
            static function (): void {
                self::fail('No message should have been delivered.');
            },
            static fn (): bool => $connection->readCalls >= 1,
        );

        $this->assertSame(1, $connection->pingCalls);
    }

    public function testTicksIdleCallbackAfterDiscardingAMalformedMessage(): void
    {
        $connection           = new FakeRedisConnection();
        $connection->messages = [
            new RedisSubscriptionMessage('app:sse:public.news', '{invalid'),
        ];
        $subscriber = $this->subscriber([$connection]);
        $idleCalls  = 0;

        $subscriber->subscribe(
            ['public.news'],
            static function (): void {
                self::fail('Malformed messages must not be delivered.');
            },
            static fn (): bool => $connection->readCalls >= 1,
            static function () use (&$idleCalls): void {
                $idleCalls++;
            },
        );

        $this->assertSame(1, $idleCalls);
    }

    public function testBoundedIdSetEvictsOldestId(): void
    {
        $set = new BoundedEventIdSet(2);

        $this->assertFalse($set->containsOrAdd('one'));
        $this->assertFalse($set->containsOrAdd('two'));
        $this->assertTrue($set->containsOrAdd('one'));
        $this->assertFalse($set->containsOrAdd('three'));
        $this->assertFalse($set->containsOrAdd('one'));
    }

    /**
     * @param list<FakeRedisConnection> $connections
     */
    private function subscriber(array $connections, int $maxReconnectAttempts = 0): RedisSubscriber
    {
        return new RedisSubscriber(
            new RedisConfig(
                maxReconnectAttempts: $maxReconnectAttempts,
                reconnectDelayMilliseconds: 0,
            ),
            $this->serializer,
            new FakeRedisConnectionFactory($connections),
        );
    }

    private function payload(string $channel, string $id): string
    {
        return $this->serializer->serialize($channel, new SseEvent('test.event', id: $id));
    }
}
