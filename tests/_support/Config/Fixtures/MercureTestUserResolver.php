<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

final class MercureTestUserResolver implements UserResolverInterface
{
    public static ?object $user = null;

    public function resolve(): ?object
    {
        return self::$user;
    }
}
