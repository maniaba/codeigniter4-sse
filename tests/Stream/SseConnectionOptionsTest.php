<?php

declare(strict_types=1);

namespace Tests\Stream;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Stream\SseConnectionOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SseConnectionOptionsTest extends TestCase
{
    public function testCreatesOptionsFromSseConfig(): void
    {
        $config                       = new Sse();
        $config->heartbeatInterval    = 7;
        $config->maxConnectionSeconds = 60;
        $config->retryMilliseconds    = 1500;
        $config->emitConnectedEvent   = false;

        $options = SseConnectionOptions::fromConfig($config);

        $this->assertSame(7, $options->heartbeatInterval);
        $this->assertSame(60, $options->maximumConnectionSeconds);
        $this->assertSame(1500, $options->retryMilliseconds);
        $this->assertFalse($options->emitConnectedEvent);
    }

    #[DataProvider('provideRejectsInvalidOptions')]
    public function testRejectsInvalidOptions(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /**
     * @return iterable<string, array{callable(): SseConnectionOptions}>
     */
    public static function provideRejectsInvalidOptions(): iterable
    {
        yield 'heartbeat' => [
            static fn (): SseConnectionOptions => new SseConnectionOptions(heartbeatInterval: 0),
        ];

        yield 'lifetime' => [
            static fn (): SseConnectionOptions => new SseConnectionOptions(maximumConnectionSeconds: 0),
        ];

        yield 'retry' => [
            static fn (): SseConnectionOptions => new SseConnectionOptions(retryMilliseconds: -1),
        ];
    }
}
