<?php

declare(strict_types=1);

namespace Tests\Stream;

use DateTimeImmutable;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\Event\BrokerMessage;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedEventIdGenerator;
use Tests\Support\RecordingSseOutput;
use Tests\Support\RecordingSubscriber;

/**
 * @internal
 */
final class SseConnectionManagerTest extends TestCase
{
    public function testStreamsConnectedAndBrokerEventsWithIds(): void
    {
        $message = new BrokerMessage(
            'orders.918',
            new SseEvent(
                'order.updated',
                ['status' => 'paid'],
                'order-event',
                new DateTimeImmutable('2026-07-30T19:10:00+00:00'),
            ),
        );
        $subscriber = new RecordingSubscriber([$message]);
        $output     = new RecordingSseOutput();
        $manager    = new SseConnectionManager(
            $subscriber,
            new JsonEventSerializer(),
            new EventFactory(new FixedEventIdGenerator('connected-event')),
        );

        $manager->stream($output, ['orders.918']);

        $this->assertSame(['orders.918'], $subscriber->channels);
        $this->assertSame([3000], $output->retries);
        $this->assertCount(2, $output->events);
        $this->assertSame('sse.connected', $output->events[0]['event']);
        $this->assertSame('order.updated', $output->events[1]['event']);
        $this->assertSame('order-event', $output->events[1]['id']);

        $payload = json_decode($output->events[1]['data'], true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('orders.918', $payload['channel']);
        $this->assertSame('paid', $payload['data']['status']);
        $this->assertSame(1, $payload['version']);
    }

    public function testFailedOutputStopsTheSubscriptionImmediately(): void
    {
        $messages = [
            new BrokerMessage('public.news', new SseEvent('news.created', [], 'one')),
            new BrokerMessage('public.news', new SseEvent('news.created', [], 'two')),
        ];
        $subscriber = new RecordingSubscriber($messages);
        $writes     = 0;
        $output     = new class ($writes) implements SseOutputInterface {
            public function __construct(private int &$writes)
            {
            }

            public function event(string $data, ?string $event = null, ?string $id = null): bool
            {
                $this->writes++;

                return false;
            }

            public function comment(string $text): bool
            {
                return false;
            }

            public function retry(int $milliseconds): bool
            {
                return true;
            }

            public function isClientConnected(): bool
            {
                // A failed write is authoritative even if PHP has not yet
                // updated connection_aborted().
                return true;
            }
        };
        $manager = new SseConnectionManager(
            $subscriber,
            new JsonEventSerializer(),
            new EventFactory(new FixedEventIdGenerator()),
            emitConnectedEvent: false,
        );

        $manager->stream($output, ['public.news']);

        $this->assertSame(1, $writes);
    }

    public function testFailedRetryWriteDoesNotStartTheSubscriber(): void
    {
        $subscriber        = new RecordingSubscriber();
        $output            = new RecordingSseOutput();
        $output->connected = false;
        $manager           = new SseConnectionManager(
            $subscriber,
            new JsonEventSerializer(),
            new EventFactory(new FixedEventIdGenerator()),
        );

        $manager->stream($output, ['public.news']);

        $this->assertSame([3000], $output->retries);
        $this->assertSame([], $subscriber->channels);
        $this->assertSame([], $output->events);
    }
}
