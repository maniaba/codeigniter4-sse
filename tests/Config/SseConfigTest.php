<?php

declare(strict_types=1);

namespace Tests\Config;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Authorization\Channels\PublicChannel;
use Maniaba\CodeIgniterSse\Config\Sse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SseConfigTest extends TestCase
{
    public function testRejectsCrossSiteBootstrapByDefault(): void
    {
        $this->assertTrue((new Sse())->rejectCrossSiteBootstrap);
    }

    public function testRegistersPublicChannelByDefault(): void
    {
        $this->assertSame([PublicChannel::class], (new Sse())->channels);
    }

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
    }

    public function testBrokerSpecificValidationIsLeftToBrokerFactories(): void
    {
        $config         = new Sse();
        $config->broker = 'mercure';

        $config->validate();

        $this->assertSame('mercure', $config->broker);
    }
}
