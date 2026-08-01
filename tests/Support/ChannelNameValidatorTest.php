<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChannelNameValidatorTest extends TestCase
{
    public function testAllowsValidChannelNames(): void
    {
        (new ChannelNameValidator())->assertValid('public.news');

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsPatternSelectors(): void
    {
        $this->expectException(InvalidChannelException::class);

        (new ChannelNameValidator())->assertValid('public.*');
    }
}
