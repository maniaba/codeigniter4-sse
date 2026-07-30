<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface EventIdGeneratorInterface
{
    public function generate(): string;
}
