<?php

declare(strict_types=1);

namespace Tests\Authorization;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Authorization\ChannelRegistry;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChannelRegistryTest extends TestCase
{
    public function testMatchesExactAndParameterizedChannels(): void
    {
        $registry = new ChannelRegistry([
            new class () implements ChannelDefinitionInterface {
                public static function pattern(): string
                {
                    return 'tenants.{tenantId}.dashboard';
                }

                public function authorize(ChannelAuthorizationContext $context): bool
                {
                    return true;
                }
            },
        ]);

        $match = $registry->match('tenants.acme.dashboard');

        $this->assertNotNull($match);
        $this->assertSame('tenants.acme.dashboard', $match->channel());
        $this->assertSame('tenants.{tenantId}.dashboard', $match->pattern());
        $this->assertSame(['tenantId' => 'acme'], $match->parameters());
        $this->assertNull($registry->match('tenants.acme.reports'));
    }

    public function testFinalWildcardMatchesChannelsAndPatternSelectors(): void
    {
        $registry = new ChannelRegistry([
            new class () implements ChannelDefinitionInterface {
                public static function pattern(): string
                {
                    return 'public.*';
                }

                public function authorize(ChannelAuthorizationContext $context): bool
                {
                    return true;
                }
            },
        ]);

        $this->assertTrue($registry->has('public.news'));
        $this->assertTrue($registry->has('public.news.world'));
        $this->assertTrue($registry->has('public.*'));
        $this->assertFalse($registry->has('users.*'));
    }

    public function testMoreSpecificPatternWinsOverEarlierBroadPattern(): void
    {
        $broad = new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'users.*';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };

        $specific = new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'users.{userId}.notifications';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };

        $match = (new ChannelRegistry([$broad, $specific]))->match('users.42.notifications');

        $this->assertNotNull($match);
        $this->assertSame($specific, $match->definition());
    }

    public function testRejectsInvalidChannelPatterns(): void
    {
        $definition = new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'users.*.notifications';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('final segment');

        new ChannelRegistry([$definition]);
    }

    public function testRejectsDuplicateChannelPatterns(): void
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registered more than once');

        new ChannelRegistry([$definition, $definition]);
    }

    public function testRejectsEquivalentParameterizedChannelPatterns(): void
    {
        $first = new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'users.{id}';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };

        $second = new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'users.{userId}';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registered more than once');

        new ChannelRegistry([$first, $second]);
    }
}
