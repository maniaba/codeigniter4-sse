<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

final readonly class RedisSubscriptionMessage
{
    public function __construct(
        public string $channel,
        public string $payload,
        public ?string $pattern = null,
    ) {
    }

    public function isPatternMessage(): bool
    {
        return $this->pattern !== null;
    }
}
