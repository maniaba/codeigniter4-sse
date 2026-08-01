<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\RedisChannelPattern;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RedisChannelPatternTest extends TestCase
{
    public function testNormalizesValidPattern(): void
    {
        $pattern = new RedisChannelPattern(' public.* ');

        $this->assertSame('public.*', $pattern->value());
        $this->assertSame('public.*', (string) $pattern);
    }

    #[DataProvider('provideRejectsInvalidPattern')]
    public function testRejectsInvalidPattern(string $pattern): void
    {
        $this->expectException(InvalidChannelException::class);

        new RedisChannelPattern($pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsInvalidPattern(): iterable
    {
        yield 'empty' => [''];

        yield 'too long' => [str_repeat('a', 201)];

        yield 'double dot' => ['public..*'];

        yield 'leading dot' => ['.public.*'];

        yield 'trailing dot' => ['public.*.'];

        yield 'invalid character' => ['public.{news}'];
    }
}
