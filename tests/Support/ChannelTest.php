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
    public function testChannelCanBeBuiltFromAString(): void
    {
        $channel = new Channel('any.domain_42-event');

        $this->assertSame('any.domain_42-event', $channel->value());
        $this->assertSame('any.domain_42-event', (string) $channel);
        $this->assertSame($channel, Channel::from($channel));
        $this->assertSame('any.domain_42-event', (string) Channel::from('any.domain_42-event'));
    }

    public function testChannelCanBeBuiltFromGenericSegments(): void
    {
        $this->assertSame('any.42.dashboard', (string) Channel::join('any', 42, 'dashboard'));
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
