<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Support\Channel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChannelTest extends TestCase
{
    public function testChannelHelpersBuildLogicalNames(): void
    {
        $this->assertSame('public.news', (string) Channel::publicChannel('news'));
        $this->assertSame('users.42', (string) Channel::user(42));
        $this->assertSame('tenants.7.dashboard', (string) Channel::tenant(7, 'dashboard'));
        $this->assertSame('orders.918', (string) Channel::order(918));
        $this->assertSame('projects.15.activity', (string) Channel::project(15, 'activity'));
    }

    #[DataProvider('provideUnsafeChannelNamesAreRejected')]
    public function testUnsafeChannelNamesAreRejected(string $channel): void
    {
        $this->expectException(InvalidChannelException::class);

        new Channel($channel);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsafeChannelNamesAreRejected(): iterable
    {
        yield 'empty' => [''];

        yield 'wildcard' => ['users.*'];

        yield 'empty segment' => ['users..42'];

        yield 'space' => ['users. 42'];

        yield 'control' => ["users.42\nadmin"];
    }
}
