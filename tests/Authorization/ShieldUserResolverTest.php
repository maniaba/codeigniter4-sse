<?php

declare(strict_types=1);

namespace {
    use Tests\Authorization\ShieldUserResolverTest;

    if (! function_exists('auth')) {
        function auth(): object
        {
            return ShieldUserResolverTest::$auth;
        }
    }
}

namespace Tests\Authorization {
    use Maniaba\CodeIgniterSse\Authorization\ShieldUserResolver;
    use PHPUnit\Framework\TestCase;

    /**
     * @internal
     */
    final class ShieldUserResolverTest extends TestCase
    {
        public static object $auth;

        public function testReturnsTheCurrentShieldUser(): void
        {
            $user       = (object) ['id' => 42];
            self::$auth = new class ($user) {
                public function __construct(
                    private readonly ?object $user,
                ) {
                }

                public function user(): ?object
                {
                    return $this->user;
                }
            };

            $this->assertSame($user, (new ShieldUserResolver())->resolve());
        }

        public function testReturnsNullWhenShieldHasNoAuthenticatedUser(): void
        {
            self::$auth = new class () {
                public function user(): null
                {
                    return null;
                }
            };

            $this->assertNull((new ShieldUserResolver())->resolve());
        }
    }
}
