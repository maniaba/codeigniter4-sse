<?php

declare(strict_types=1);

namespace Tests\Event;

use DateTimeImmutable;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Exception\InvalidEventException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedEventIdGenerator;

/**
 * @internal
 */
final class SseEventTest extends TestCase
{
    public function testEventExposesStableEnvelopeFields(): void
    {
        $time  = new DateTimeImmutable('2026-07-30T19:10:00+00:00');
        $event = new SseEvent('order.updated', ['orderId' => 918], 'event-1', $time);

        $this->assertSame('event-1', $event->id());
        $this->assertSame('order.updated', $event->name());
        $this->assertSame(['orderId' => 918], $event->data());
        $this->assertSame($time, $event->occurredAt());
        $this->assertSame([
            'id'         => 'event-1',
            'event'      => 'order.updated',
            'data'       => ['orderId' => 918],
            'occurredAt' => '2026-07-30T19:10:00+00:00',
        ], $event->jsonSerialize());
    }

    public function testFactoryUsesInjectedIdGenerator(): void
    {
        $factory = new EventFactory(new FixedEventIdGenerator('fixed-id'));

        $this->assertSame('fixed-id', $factory->create('test.created')->id());
    }

    public function testInvalidEventNameIsRejected(): void
    {
        $this->expectException(InvalidEventException::class);

        new SseEvent("order\nupdated");
    }

    public function testNullByteInEventIdIsRejected(): void
    {
        $this->expectException(InvalidEventException::class);

        new SseEvent('profile.updated', id: "event\0injected");
    }
}
