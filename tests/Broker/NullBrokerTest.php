<?php

declare(strict_types=1);

namespace Tests\Broker;

use Maniaba\CodeIgniterSse\Broker\Null\NullBroker;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NullBrokerTest extends TestCase
{
    public function testSubscriberDoesNotCauseAnImmediateReconnectLoop(): void
    {
        $checks = 0;
        $idle   = 0;

        (new NullBroker())->subscribe(
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
}
