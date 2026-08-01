<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Support;

use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;

final readonly class ChannelNameValidator implements ChannelSelectorValidatorInterface
{
    public function assertValid(string $selector): void
    {
        Channel::from($selector);
    }
}
