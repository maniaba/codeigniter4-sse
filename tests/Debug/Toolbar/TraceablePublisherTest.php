<?php

declare(strict_types=1);

namespace Tests\Debug\Toolbar;

use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Debug\Toolbar\SseEventHistory;
use Maniaba\CodeIgniterSse\Debug\Toolbar\TraceablePublisher;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Support\Tests\RecordingPublisher;

/**
 * @internal
 */
final class TraceablePublisherTest extends TestCase
{
    protected function tearDown(): void
    {
        SseEventHistory::clear();
    }

    public function testItRecordsPublishedEventsWithoutPayloadValues(): void
    {
        $publisher = new RecordingPublisher();
        $event     = new SseEvent('notification.created', [
            'title' => 'Paid',
            'token' => 'secret-value',
        ], 'event-1');

        (new TraceablePublisher($publisher))->publish('users.42', $event);

        $history = SseEventHistory::all();

        $this->assertCount(1, $publisher->published);
        $this->assertCount(1, $history);
        $this->assertSame('published', $history[0]['status']);
        $this->assertSame('users.42', $history[0]['channel']);
        $this->assertSame('notification.created', $history[0]['event']);
        $this->assertSame('event-1', $history[0]['id']);
        $this->assertSame(['title', 'token'], $history[0]['dataKeys']);
        $this->assertGreaterThan(0, $history[0]['payloadBytes']);
        $this->assertSame(RecordingPublisher::class, $history[0]['publisher']);
        $this->assertNull($history[0]['error']);
        $this->assertStringNotContainsString('secret-value', json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function testItRecordsFailedPublishAttemptsAndRethrows(): void
    {
        $publisher = new class () implements PublisherInterface {
            public function publish(string $channel, EventInterface $event): void
            {
                throw new RuntimeException(
                    'Unable to publish the SSE event after reconnecting to Redis.',
                    previous: new RuntimeException('Connection refused'),
                );
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to publish the SSE event after reconnecting to Redis.');

        try {
            (new TraceablePublisher($publisher))->publish(
                'public.news',
                new SseEvent('news.created', ['id' => 42], 'event-2'),
            );
        } finally {
            $history = SseEventHistory::all();

            $this->assertCount(1, $history);
            $this->assertSame('failed', $history[0]['status']);
            $this->assertStringContainsString(
                'Unable to publish the SSE event after reconnecting to Redis.',
                (string) $history[0]['error'],
            );
            $this->assertStringContainsString('Connection refused', (string) $history[0]['error']);
        }
    }

    public function testItKeepsOnlyTheConfiguredLimit(): void
    {
        $publisher = new RecordingPublisher();
        $traceable = new TraceablePublisher($publisher, 2);

        $traceable->publish('public.news', new SseEvent('one.created', id: 'event-1'));
        $traceable->publish('public.news', new SseEvent('two.created', id: 'event-2'));
        $traceable->publish('public.news', new SseEvent('three.created', id: 'event-3'));

        $history = SseEventHistory::all();

        $this->assertCount(2, $history);
        $this->assertSame('event-2', $history[0]['id']);
        $this->assertSame('event-3', $history[1]['id']);
    }
}
