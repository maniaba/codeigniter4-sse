<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;

interface ChannelDefinitionInterface
{
    public static function pattern(): string;

    public function authorize(ChannelAuthorizationContext $context): bool;
}
