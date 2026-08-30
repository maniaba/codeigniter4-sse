<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class MercureUserChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'users.{userId}';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        MercureChannelAuthorizationRecorder::record($context);

        $user = $context->user();

        return $user !== null && (string) ($user->id ?? '') === $context->param('userId');
    }
}
