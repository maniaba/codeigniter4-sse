<?php

declare(strict_types=1);

namespace Tests\Authorization;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Authorization\Channels\PublicChannel;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PublicChannelTest extends TestCase
{
    public function testPublicChannelDefinitionAllowsAnonymousAccess(): void
    {
        $channel = new PublicChannel();

        $this->assertInstanceOf(ChannelDefinitionInterface::class, $channel);
        $this->assertSame('public.*', $channel::pattern());
        $this->assertTrue($channel->authorize(new ChannelAuthorizationContext(
            user: null,
            channel: 'public.news',
            pattern: 'public.*',
        )));
    }
}
