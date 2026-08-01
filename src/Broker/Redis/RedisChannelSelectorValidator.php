<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Support\Channel;

final readonly class RedisChannelSelectorValidator implements ChannelSelectorValidatorInterface
{
    public function __construct(
        private RedisConfig $config,
    ) {
    }

    public function assertValid(string $selector): void
    {
        if (strpbrk($selector, '*?[') === false) {
            Channel::from($selector);

            return;
        }

        if (! $this->config->allowPatternSubscriptions) {
            throw new InvalidChannelException('Redis pattern subscriptions are disabled.');
        }

        new RedisChannelPattern($selector);
    }
}
