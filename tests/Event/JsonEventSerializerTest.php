<?php

declare(strict_types=1);

namespace Tests\Event;

use DateTimeImmutable;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Exception\InvalidEventPayloadException;
use Maniaba\CodeIgniterSse\Exception\UnsupportedEventVersionException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonEventSerializerTest extends TestCase
{
    private JsonEventSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonEventSerializer();
    }

    public function testRoundTripPreservesVersionedEnvelope(): void
    {
        $event = new SseEvent(
            'order.updated',
            ['orderId' => 918, 'total' => 125.40],
            'event-918',
            new DateTimeImmutable('2026-07-30T19:10:00+00:00'),
        );
        $payload = $this->serializer->serialize('users.42', $event);
        $message = $this->serializer->deserialize($payload);

        $this->assertSame('users.42', $message->channel());
        $this->assertSame('event-918', $message->id());
        $this->assertSame(1, $message->version());
        $this->assertSame($event->data(), $message->event()->data());
        $this->assertStringContainsString('"version":1', $payload);
        $this->assertStringContainsString('"total":125.4', $payload);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(InvalidEventPayloadException::class);

        $this->serializer->deserialize('{');
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $payload = json_encode([
            'id'         => 'event-1',
            'event'      => 'test.created',
            'channel'    => 'public.news',
            'data'       => [],
            'occurredAt' => '2026-07-30T19:10:00+00:00',
            'version'    => 2,
        ], JSON_THROW_ON_ERROR);

        $this->expectException(UnsupportedEventVersionException::class);

        $this->serializer->deserialize($payload);
    }
}
