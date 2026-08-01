<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;

final class ConfiguredChannelAuthorizer implements ChannelAuthorizerInterface
{
    /**
     * @var list<string>
     */
    public static array $channels = [];

    public function authorize(?object $user, string $channel): bool
    {
        self::$channels[] = $channel;

        return true;
    }
}
