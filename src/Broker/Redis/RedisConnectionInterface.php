<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

interface RedisConnectionInterface
{
    public function connect(): void;

    public function close(): void;

    public function isConnected(): bool;

    public function ping(): bool;

    public function publish(string $channel, string $payload): int;

    /**
     * @param list<string> $channels
     * @param list<string> $patterns
     */
    public function subscribe(array $channels, array $patterns = []): void;

    /**
     * Waits for the next Pub/Sub message.
     *
     * A null result means that no message was received before the timeout.
     */
    public function readMessage(float $timeoutSeconds): ?RedisSubscriptionMessage;
}
