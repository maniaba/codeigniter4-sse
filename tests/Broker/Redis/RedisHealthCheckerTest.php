<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisHealthChecker;
use PHPUnit\Framework\TestCase;
use Support\Tests\Broker\Redis\Fixtures\FakeRedisConnection;
use Support\Tests\Broker\Redis\Fixtures\FakeRedisConnectionFactory;

/**
 * @internal
 */
final class RedisHealthCheckerTest extends TestCase
{
    public function testReportsHealthyConnectionAndAlwaysClosesIt(): void
    {
        $connection = new FakeRedisConnection();
        $checker    = new RedisHealthChecker(new FakeRedisConnectionFactory([$connection]));

        $this->assertTrue($checker->check());
        $this->assertSame(1, $connection->pingCalls);
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testReportsConnectionFailureAsUnhealthy(): void
    {
        $connection                 = new FakeRedisConnection();
        $connection->connectFailure = new RedisConnectionException('offline');
        $checker                    = new RedisHealthChecker(new FakeRedisConnectionFactory([$connection]));

        $this->assertFalse($checker->check());
        $this->assertSame($connection->connectFailure, $checker->lastError());
        $this->assertSame(1, $connection->closeCalls);
    }
}
