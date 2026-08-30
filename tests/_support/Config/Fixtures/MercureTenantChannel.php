<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class MercureTenantChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'tenants.{tenantId}';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        MercureChannelAuthorizationRecorder::record($context);

        $user = $context->user();

        if ($user === null) {
            return false;
        }

        $tenantIds = $user->tenantIds ?? [];

        return is_array($tenantIds) && in_array($context->param('tenantId'), array_map(strval(...), $tenantIds), true);
    }
}
