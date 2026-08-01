<?php

declare(strict_types=1);

namespace Tests\Broker;

use Maniaba\CodeIgniterSse\Broker\InMemory\InMemoryBroker;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class InMemoryBrokerTest extends TestCase
{
    public function testSubscriptionReceivesOnlyRequestedChannels(): void
    {
        $broker = new InMemoryBroker();
        $broker->publish('users.42', new SseEvent('notification.created', [], 'one'));
        $broker->publish('users.7', new SseEvent('notification.created', [], 'two'));

        $ids = [];
        $broker->subscribe(
            ['users.42'],
            static function ($message) use (&$ids): void {
                $ids[] = $message->id();
            },
        );

        $this->assertSame(['one'], $ids);
    }

    public function testIdleSubscriptionStaysOpenUntilStopCallback(): void
    {
        $broker = new InMemoryBroker();
        $checks = 0;
        $idle   = 0;

        $broker->subscribe(
            ['public.news'],
            static function (): void {
            },
            static function () use (&$checks): bool {
                return ++$checks >= 2;
            },
            static function () use (&$idle): void {
                $idle++;
            },
        );

        $this->assertSame(1, $idle);
    }

    public function testOpenSubscriptionReceivesMessagesPublishedAfterItStarts(): void
    {
        $broker    = new InMemoryBroker();
        $received  = [];
        $delivered = false;
        $idle      = 0;

        $broker->subscribe(
            ['public.news'],
            static function ($message) use (&$received, &$delivered): void {
                $received[] = $message->id();
                $delivered  = true;
            },
            static function () use (&$delivered): bool {
                return $delivered;
            },
            static function () use ($broker, &$idle): void {
                $idle++;

                if ($idle === 1) {
                    $broker->publish('public.news', new SseEvent('news.created', [], 'later'));
                }
            },
        );

        $this->assertSame(['later'], $received);
    }
}
