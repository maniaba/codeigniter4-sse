<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

final class NullUserResolver implements UserResolverInterface
{
    public function resolve(): ?object
    {
        return null;
    }
}
