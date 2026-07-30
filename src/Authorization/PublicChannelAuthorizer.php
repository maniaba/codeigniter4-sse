<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Support\Channel;
use Maniaba\CodeIgniterSse\Support\ChannelPattern;

/**
 * Secure default: anonymous access is allowed only to public.* channels.
 */
final class PublicChannelAuthorizer implements ChannelAuthorizerInterface
{
    public function authorize(?object $user, string $channel): bool
    {
        $channel = strpbrk($channel, '*?[') === false
            ? Channel::from($channel)->value()
            : (new ChannelPattern($channel))->value();

        return str_starts_with($channel, 'public.');
    }
}
