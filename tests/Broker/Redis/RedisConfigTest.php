<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConfigurationException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RedisConfigTest extends TestCase
{
    public function testBuildsTcpAndTlsEndpointsIncludingIpv6(): void
    {
        $this->assertSame('tcp://127.0.0.1:6379', (new RedisConfig())->endpoint());
        $this->assertSame(
            'tls://[2001:db8::1]:6380',
            (new RedisConfig(host: '2001:db8::1', port: 6380, scheme: 'tls'))->endpoint(),
        );
    }

    public function testNormalizesEmptyCredentials(): void
    {
        $config = new RedisConfig(password: '', username: '');

        $this->assertNull($config->password);
        $this->assertNull($config->username);
    }

    #[DataProvider('provideRejectsInvalidConfiguration')]
    public function testRejectsInvalidConfiguration(callable $factory): void
    {
        $this->expectException(RedisConfigurationException::class);

        $factory();
    }

    /**
     * @return iterable<string, array{callable(): RedisConfig}>
     */
    public static function provideRejectsInvalidConfiguration(): iterable
    {
        yield 'host' => [static fn (): RedisConfig => new RedisConfig(host: '')];

        yield 'port' => [static fn (): RedisConfig => new RedisConfig(port: 0)];

        yield 'scheme' => [static fn (): RedisConfig => new RedisConfig(scheme: 'redis')];

        yield 'database' => [static fn (): RedisConfig => new RedisConfig(database: -1)];

        yield 'connect timeout' => [static fn (): RedisConfig => new RedisConfig(connectTimeout: 0.0)];

        yield 'read timeout' => [static fn (): RedisConfig => new RedisConfig(readTimeout: 0.0)];

        yield 'poll interval' => [static fn (): RedisConfig => new RedisConfig(pollIntervalSeconds: 0.0)];

        yield 'subscriber ping interval' => [
            static fn (): RedisConfig => new RedisConfig(subscriberPingIntervalSeconds: 0.0),
        ];

        yield 'reconnect attempts' => [static fn (): RedisConfig => new RedisConfig(maxReconnectAttempts: -1)];

        yield 'reconnect delay' => [static fn (): RedisConfig => new RedisConfig(reconnectDelayMilliseconds: -1)];

        yield 'dedup capacity' => [static fn (): RedisConfig => new RedisConfig(deduplicationCapacity: 0)];

        yield 'payload minimum' => [static fn (): RedisConfig => new RedisConfig(maxPayloadBytes: 1023)];

        yield 'payload maximum' => [
            static fn (): RedisConfig => new RedisConfig(maxPayloadBytes: 536_870_913),
        ];

        yield 'response elements' => [
            static fn (): RedisConfig => new RedisConfig(maxResponseElements: 0),
        ];

        yield 'response depth' => [static fn (): RedisConfig => new RedisConfig(maxResponseDepth: 0)];

        yield 'username without password' => [
            static fn (): RedisConfig => new RedisConfig(username: 'application'),
        ];

        yield 'prefix newline' => [static fn (): RedisConfig => new RedisConfig(channelPrefix: "sse:\n")];

        yield 'pattern prefix glob' => [
            static fn (): RedisConfig => new RedisConfig(
                channelPrefix: 'tenant:*:sse:',
                allowPatternSubscriptions: true,
            ),
        ];

        yield 'empty client name' => [static fn (): RedisConfig => new RedisConfig(clientName: '')];
    }
}
