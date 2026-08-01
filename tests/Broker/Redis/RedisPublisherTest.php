<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisCommandException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisPublishException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use PHPUnit\Framework\TestCase;
use Support\Tests\Broker\Redis\Fixtures\FakeRedisConnection;
use Support\Tests\Broker\Redis\Fixtures\FakeRedisConnectionFactory;

/**
 * @internal
 */
final class RedisPublisherTest extends TestCase
{
    public function testSerializesPrefixesAndPublishesUsingReusableDedicatedConnection(): void
    {
        $connection = new FakeRedisConnection();
        $factory    = new FakeRedisConnectionFactory([$connection]);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->exactly(2))
            ->method('serialize')
            ->willReturnCallback(static fn (string $channel): string => '{"channel":"' . $channel . '"}');
        $publisher = new RedisPublisher(
            new RedisConfig(channelPrefix: 'project:sse:'),
            $serializer,
            $factory,
        );

        $publisher->publish('users.42', new SseEvent('first', id: 'one'));
        $publisher->publish('orders.918', new SseEvent('second', id: 'two'));

        $this->assertSame(1, $factory->createCalls);
        $this->assertSame(1, $connection->connectCalls);
        $this->assertSame([
            ['channel' => 'project:sse:users.42', 'payload' => '{"channel":"users.42"}'],
            ['channel' => 'project:sse:orders.918', 'payload' => '{"channel":"orders.918"}'],
        ], $connection->published);

        $publisher->close();
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testRetriesTransportFailureWithFreshConnection(): void
    {
        $first                 = new FakeRedisConnection();
        $first->publishResults = [new RedisConnectionException('connection lost')];
        $second                = new FakeRedisConnection();
        $factory               = new FakeRedisConnectionFactory([$first, $second]);
        $serializer            = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('payload');
        $publisher = new RedisPublisher(
            new RedisConfig(maxReconnectAttempts: 1, reconnectDelayMilliseconds: 0),
            $serializer,
            $factory,
        );

        $publisher->publish('public.news', new SseEvent('news.updated', id: 'one'));

        $this->assertSame(2, $factory->createCalls);
        $this->assertSame(1, $first->closeCalls);
        $this->assertSame([
            ['channel' => 'app:sse:public.news', 'payload' => 'payload'],
        ], $second->published);
    }

    public function testStopsAfterConfiguredReconnectAttempts(): void
    {
        $first                  = new FakeRedisConnection();
        $first->connectFailure  = new RedisConnectionException('offline');
        $second                 = new FakeRedisConnection();
        $second->connectFailure = new RedisConnectionException('still offline');
        $serializer             = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('payload');
        $publisher = new RedisPublisher(
            new RedisConfig(maxReconnectAttempts: 1, reconnectDelayMilliseconds: 0),
            $serializer,
            new FakeRedisConnectionFactory([$first, $second]),
        );

        $this->expectException(RedisPublishException::class);

        $publisher->publish('public.news', new SseEvent('news.updated'));
    }

    public function testDoesNotRetryCommandErrors(): void
    {
        $connection                 = new FakeRedisConnection();
        $connection->publishResults = [new RedisCommandException('READONLY')];
        $factory                    = new FakeRedisConnectionFactory([$connection]);
        $serializer                 = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn('payload');
        $publisher = new RedisPublisher(
            new RedisConfig(maxReconnectAttempts: 5, reconnectDelayMilliseconds: 0),
            $serializer,
            $factory,
        );

        try {
            $publisher->publish('public.news', new SseEvent('news.updated'));
            $this->fail('Expected RedisPublishException was not thrown.');
        } catch (RedisPublishException $exception) {
            $this->assertInstanceOf(RedisCommandException::class, $exception->getPrevious());
        }

        $this->assertSame(1, $factory->createCalls);
    }

    public function testRejectsPayloadAboveTheConfiguredLimitBeforeConnecting(): void
    {
        $connection = new FakeRedisConnection();
        $factory    = new FakeRedisConnectionFactory([$connection]);
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturn(str_repeat('x', 1025));
        $publisher = new RedisPublisher(
            new RedisConfig(maxPayloadBytes: 1024),
            $serializer,
            $factory,
        );

        $this->expectException(RedisPublishException::class);
        $this->expectExceptionMessage('exceeds the Redis payload limit');

        try {
            $publisher->publish('public.news', new SseEvent('news.updated'));
        } finally {
            $this->assertSame(0, $factory->createCalls);
        }
    }
}
