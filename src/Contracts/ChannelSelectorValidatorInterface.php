<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface ChannelSelectorValidatorInterface
{
    public function assertValid(string $selector): void;
}
