<?php

declare(strict_types=1);

namespace Support\Tests\Broker\Redis\Fixtures;

use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionInterface;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriptionMessage;
use Throwable;

final class FakeRedisConnection implements RedisConnectionInterface
{
    public bool $connected   = false;
    public int $connectCalls = 0;
    public int $closeCalls   = 0;
    public int $pingCalls    = 0;
    public int $readCalls    = 0;

    /**
     * @var list<array{channel: string, payload: string}>
     */
    public array $published = [];

    /**
     * @var list<string>
     */
    public array $subscribedChannels = [];

    /**
     * @var list<string>
     */
    public array $subscribedPatterns = [];

    /**
     * @var list<RedisSubscriptionMessage|Throwable|null>
     */
    public array $messages = [];

    /**
     * @var list<int|Throwable>
     */
    public array $publishResults = [];

    public ?Throwable $connectFailure   = null;
    public ?Throwable $subscribeFailure = null;
    public bool $pingResult             = true;

    public function connect(): void
    {
        $this->connectCalls++;

        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        $this->connected = true;
    }

    public function close(): void
    {
        $this->closeCalls++;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function ping(): bool
    {
        $this->pingCalls++;

        return $this->pingResult;
    }

    public function publish(string $channel, string $payload): int
    {
        $this->published[] = ['channel' => $channel, 'payload' => $payload];
        $result            = array_shift($this->publishResults) ?? 1;

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function subscribe(array $channels, array $patterns = []): void
    {
        if ($this->subscribeFailure !== null) {
            throw $this->subscribeFailure;
        }

        $this->subscribedChannels = $channels;
        $this->subscribedPatterns = $patterns;
    }

    public function readMessage(float $timeoutSeconds): ?RedisSubscriptionMessage
    {
        $this->readCalls++;
        $message = array_shift($this->messages);

        if ($message instanceof Throwable) {
            throw $message;
        }

        return $message;
    }
}
