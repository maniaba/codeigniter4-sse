<?php

declare(strict_types=1);

namespace Tests\Config;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Config\Sse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SseConfigTest extends TestCase
{
    /**
     * @param callable(Sse):void $configure
     */
    #[DataProvider('provideInvalidOperationalValuesFailFast')]
    public function testInvalidOperationalValuesFailFast(callable $configure): void
    {
        $config = new Sse();
        $configure($config);

        $this->expectException(InvalidArgumentException::class);

        $config->validate();
    }

    /**
     * @return iterable<string, array{callable(Sse): void}>
     */
    public static function provideInvalidOperationalValuesFailFast(): iterable
    {
        yield 'negative retry' => [
            static function (Sse $config): void {
                $config->retryMilliseconds = -1;
            },
        ];

        yield 'zero heartbeat' => [
            static function (Sse $config): void {
                $config->heartbeatInterval = 0;
            },
        ];

        yield 'zero lifetime' => [
            static function (Sse $config): void {
                $config->maxConnectionSeconds = 0;
            },
        ];

        yield 'too many channels' => [
            static function (Sse $config): void {
                $config->maxChannelsPerConnection = 101;
            },
        ];

        yield 'credentialed wildcard CORS' => [
            static function (Sse $config): void {
                $config->allowedOrigins  = ['*'];
                $config->withCredentials = true;
            },
        ];

        yield 'mercure patterns' => [
            static function (Sse $config): void {
                $config->broker                    = 'mercure';
                $config->allowPatternSubscriptions = true;
                $config->mercure                   = [
                    'publisherKey'  => 'publisher-test-secret',
                    'subscriberKey' => 'subscriber-test-secret',
                ];
            },
        ];

        yield 'missing mercure keys' => [
            static function (Sse $config): void {
                $config->broker = 'mercure';
            },
        ];

        yield 'private mercure without subscriber authorization' => [
            static function (Sse $config): void {
                $config->broker  = 'mercure';
                $config->mercure = [
                    'private'              => true,
                    'authorizeSubscribers' => false,
                    'publisherKey'         => 'publisher-test-secret',
                ];
            },
        ];
    }
}
