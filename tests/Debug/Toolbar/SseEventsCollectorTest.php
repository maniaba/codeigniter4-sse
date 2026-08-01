<?php

declare(strict_types=1);

namespace Tests\Debug\Toolbar;

use Maniaba\CodeIgniterSse\Debug\Toolbar\SseEventHistory;
use Maniaba\CodeIgniterSse\Debug\Toolbar\SseEvents;
use Maniaba\CodeIgniterSse\Debug\Toolbar\TraceablePublisher;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use PHPUnit\Framework\TestCase;
use Support\Tests\RecordingPublisher;

/**
 * @internal
 */
final class SseEventsCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        SseEventHistory::clear();
    }

    public function testItIsVisibleEvenBeforeAnEventIsPublished(): void
    {
        $collector = new SseEvents();

        $this->assertFalse($collector->isEmpty());
        $this->assertSame(0, $collector->getBadgeValue());
        $this->assertStringContainsString(
            'No SSE events were published during this request.',
            $collector->display(),
        );
    }

    public function testItDisplaysPublishedEventMetadata(): void
    {
        (new TraceablePublisher(new RecordingPublisher()))->publish(
            'users.42',
            new SseEvent('notification.created', ['title' => 'Paid'], 'event-1'),
        );

        $collector = new SseEvents();
        $display   = $collector->display();

        $this->assertFalse($collector->isEmpty());
        $this->assertSame(1, $collector->getBadgeValue());
        $this->assertSame('1 event', $collector->getTitleDetails());
        $this->assertStringContainsString('users.42', $display);
        $this->assertStringContainsString('notification.created', $display);
        $this->assertStringContainsString('event-1', $display);
        $this->assertStringContainsString('title', $display);
        $this->assertStringNotContainsString('Paid', $display);
    }

    public function testItUsesBroadcastIcon(): void
    {
        $icon = (new SseEvents())->icon();
        $svg  = base64_decode(substr($icon, strlen('data:image/svg+xml;base64,')), true);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $icon);
        $this->assertIsString($svg);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
        $this->assertStringContainsString('M18.364 19.364a9 9 0 1 0-12.728 0', $svg);
        $this->assertStringContainsString('stroke="#dd4814"', $svg);
    }
}
