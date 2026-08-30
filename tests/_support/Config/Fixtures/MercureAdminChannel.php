<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class MercureAdminChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'admin.*';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        MercureChannelAuthorizationRecorder::record($context);

        $user = $context->user();

        return $user !== null && ($user->role ?? null) === 'admin';
    }
}
