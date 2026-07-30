<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface UserResolverInterface
{
    public function resolve(): ?object;
}
