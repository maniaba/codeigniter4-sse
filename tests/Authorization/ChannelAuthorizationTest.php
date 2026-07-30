<?php

declare(strict_types=1);

namespace Tests\Authorization;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorization;
use Maniaba\CodeIgniterSse\Authorization\PublicChannelAuthorizer;
use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChannelAuthorizationTest extends TestCase
{
    public function testDefaultAuthorizerAllowsOnlyPublicChannels(): void
    {
        $authorization = new ChannelAuthorization(new PublicChannelAuthorizer());

        $this->assertSame(
            ['public.news'],
            $authorization->authorizeAll(null, ['public.news']),
        );

        $this->expectException(UnauthorizedChannelException::class);

        $authorization->authorizeAll(null, ['users.42']);
    }

    public function testDefaultAuthorizerUnderstandsPublicPatterns(): void
    {
        $authorization = new ChannelAuthorization(new PublicChannelAuthorizer());

        $this->assertSame(
            ['public.*'],
            $authorization->authorizeAll(null, ['public.*']),
        );

        $this->expectException(UnauthorizedChannelException::class);

        $authorization->authorizeAll(null, ['users.*']);
    }
}
