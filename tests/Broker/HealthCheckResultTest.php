<?php

declare(strict_types=1);

namespace Tests\Broker;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Broker\HealthCheckResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @internal
 */
final class HealthCheckResultTest extends TestCase
{
    public function testCreatesSuccessfulResult(): void
    {
        $result = HealthCheckResult::ok('reachable', ['detail']);

        $this->assertSame(HealthCheckResult::OK, $result->status);
        $this->assertSame('reachable', $result->summary);
        $this->assertSame(['detail'], $result->details);
        $this->assertNull($result->error);
        $this->assertTrue($result->isSuccessful());
    }

    public function testCreatesFailedResult(): void
    {
        $error  = new RuntimeException('offline');
        $result = HealthCheckResult::failed('failed', $error, ['host']);

        $this->assertSame(HealthCheckResult::FAILED, $result->status);
        $this->assertSame('failed', $result->summary);
        $this->assertSame(['host'], $result->details);
        $this->assertSame($error, $result->error);
        $this->assertFalse($result->isSuccessful());
    }

    public function testCreatesSkippedResult(): void
    {
        $result = HealthCheckResult::skipped('not supported');

        $this->assertSame(HealthCheckResult::SKIPPED, $result->status);
        $this->assertTrue($result->isSuccessful());
    }

    public function testRejectsUnknownStatus(): void
    {
        $reflection  = new ReflectionClass(HealthCheckResult::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $result = $reflection->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SSE health check status "unknown".');

        $constructor->invoke($result, 'unknown', 'bad status');
    }
}
