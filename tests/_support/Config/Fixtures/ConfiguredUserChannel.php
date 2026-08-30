<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class ConfiguredUserChannel implements ChannelDefinitionInterface
{
    /**
     * @var list<string>
     */
    public static array $channels = [];

    public static function pattern(): string
    {
        return 'users.{userId}';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        self::$channels[] = $context->channel();

        return true;
    }
}
