<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization\Channels;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

/**
 * Secure default: anonymous access is allowed only to public.* channels.
 */
final class PublicChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'public.*';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        return true;
    }
}
