<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Stream;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Config\Sse;

final readonly class SseConnectionOptions
{
    public function __construct(
        public int $heartbeatInterval = 15,
        public int $maximumConnectionSeconds = 300,
        public int $retryMilliseconds = 3000,
        public bool $emitConnectedEvent = true,
    ) {
        if ($heartbeatInterval < 1) {
            throw new InvalidArgumentException('Heartbeat interval must be at least one second.');
        }

        if ($maximumConnectionSeconds < 1) {
            throw new InvalidArgumentException('Maximum connection lifetime must be at least one second.');
        }

        if ($retryMilliseconds < 0) {
            throw new InvalidArgumentException('Retry delay must not be negative.');
        }
    }

    public static function fromConfig(Sse $config): self
    {
        return new self(
            $config->heartbeatInterval,
            $config->maxConnectionSeconds,
            $config->retryMilliseconds,
            $config->emitConnectedEvent,
        );
    }
}
