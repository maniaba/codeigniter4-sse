<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;

final class MercureChannelAuthorizationRecorder
{
    /**
     * @var list<array{user: object|null, channel: string, pattern: string, parameters: array<string, string>}>
     */
    public static array $attempts = [];

    public static function record(ChannelAuthorizationContext $context): void
    {
        self::$attempts[] = [
            'user'       => $context->user(),
            'channel'    => $context->channel(),
            'pattern'    => $context->pattern(),
            'parameters' => $context->parameters(),
        ];
    }

    public static function reset(): void
    {
        self::$attempts = [];
    }
}
