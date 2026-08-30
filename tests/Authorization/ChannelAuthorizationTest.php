<?php

declare(strict_types=1);

namespace Tests\Authorization;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorization;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Authorization\ChannelRegistry;
use Maniaba\CodeIgniterSse\Authorization\Channels\PublicChannel;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;
use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChannelAuthorizationTest extends TestCase
{
    public function testDefaultChannelRegistryAllowsOnlyPublicChannels(): void
    {
        $authorization = new ChannelAuthorization(new ChannelRegistry([new PublicChannel()]));

        $this->assertSame(
            ['public.news'],
            $authorization->authorizeAll(null, ['public.news']),
        );

        $this->expectException(UnauthorizedChannelException::class);

        $authorization->authorizeAll(null, ['users.42']);
    }

    public function testDefaultPublicChannelUnderstandsPublicPatterns(): void
    {
        $authorization = new ChannelAuthorization(new ChannelRegistry([new PublicChannel()]));

        $this->assertSame(
            ['public.*'],
            $authorization->authorizeAll(null, ['public.*']),
        );

        $this->expectException(UnauthorizedChannelException::class);

        $authorization->authorizeAll(null, ['users.*']);
    }

    public function testRegisteredChannelReceivesMatchedParameters(): void
    {
        $definition = new class () implements ChannelDefinitionInterface {
            public ?ChannelAuthorizationContext $context = null;

            public static function pattern(): string
            {
                return 'users.{userId}.notifications';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                $this->context = $context;
                $user          = $context->user();

                return $user !== null && (string) ($user->id ?? '') === $context->param('userId');
            }
        };

        $authorization = new ChannelAuthorization(new ChannelRegistry([$definition]));

        $this->assertSame(
            ['users.42.notifications'],
            $authorization->authorizeAll((object) ['id' => 42], ['users.42.notifications']),
        );

        $this->assertInstanceOf(ChannelAuthorizationContext::class, $definition->context);
        $this->assertSame('users.42.notifications', $definition->context->channel());
        $this->assertSame('users.{userId}.notifications', $definition->context->pattern());
        $this->assertSame(['userId' => '42'], $definition->context->parameters());
    }

    public function testUnknownChannelsAreDenied(): void
    {
        $authorization = new ChannelAuthorization(new ChannelRegistry([new PublicChannel()]));

        $this->expectException(UnauthorizedChannelException::class);

        $authorization->authorizeAll((object) ['id' => 42], ['orders.100']);
    }

    public function testWildcardSelectorMustMatchAnExplicitWildcardChannel(): void
    {
        $definition = new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'users.{userId}';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };

        $authorization = new ChannelAuthorization(new ChannelRegistry([$definition]));

        $this->expectException(UnauthorizedChannelException::class);

        $authorization->authorizeAll((object) ['id' => 42], ['users.*']);
    }
}
