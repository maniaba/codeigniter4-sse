<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Sse;
use Maniaba\CodeIgniterSse\Support\Channel;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedEventIdGenerator;
use Tests\Support\RecordingPublisher;

/**
 * @internal
 */
final class SseTest extends TestCase
{
    public function testConvenienceApiBuildsAndPublishesAnEvent(): void
    {
        $publisher = new RecordingPublisher();
        $sse       = new Sse(
            $publisher,
            new EventFactory(new FixedEventIdGenerator('generated-id')),
        );

        $event = $sse->publish(
            Channel::join('users', 42),
            'notification.created',
            ['title' => 'Paid'],
        );

        $this->assertSame('generated-id', $event->id());
        $this->assertSame('users.42', $publisher->published[0]['channel']);
        $this->assertSame($event, $publisher->published[0]['event']);
    }

    public function testDataCannotBePassedWithPrebuiltEvent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Sse(new RecordingPublisher()))->publish(
            'public.news',
            new SseEvent('news.created'),
            ['ignored' => true],
        );
    }
}
