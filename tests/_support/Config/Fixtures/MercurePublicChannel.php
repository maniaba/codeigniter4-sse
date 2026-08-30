<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class MercurePublicChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'public.*';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        MercureChannelAuthorizationRecorder::record($context);

        return true;
    }
}
