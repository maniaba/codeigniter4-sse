<?php

declare(strict_types=1);

namespace Tests\Helpers;

use CodeIgniter\Config\Services as FrameworkServices;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Sse;
use Tests\Support\FixedEventIdGenerator;
use Tests\Support\RecordingPublisher;

/**
 * @internal
 */
final class SseHelperTest extends CIUnitTestCase
{
    public function testHelperReturnsTheSseService(): void
    {
        $publisher = new RecordingPublisher();
        $service   = new Sse(
            $publisher,
            new EventFactory(new FixedEventIdGenerator('helper-id')),
        );

        FrameworkServices::injectMock('sse', $service);

        try {
            $event = sse()->publish('public.news', 'news.created', ['id' => 42]);

            $this->assertSame('helper-id', $event->id());
            $this->assertSame('public.news', $publisher->published[0]['channel']);
        } finally {
            FrameworkServices::resetSingle('sse');
        }
    }
}
