<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface ChannelSelectorValidatorProviderInterface
{
    public function channelSelectorValidator(): ChannelSelectorValidatorInterface;
}
