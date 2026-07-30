<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Throwable;

final class RedisHealthChecker
{
    private ?Throwable $lastError = null;

    public function __construct(
        private readonly RedisConnectionFactoryInterface $connectionFactory,
    ) {
    }

    public function check(): bool
    {
        $connection      = null;
        $this->lastError = null;

        try {
            $connection = $this->connectionFactory->create();
            $connection->connect();

            return $connection->ping();
        } catch (Throwable $error) {
            $this->lastError = $error;

            return false;
        } finally {
            $connection?->close();
        }
    }

    public function isHealthy(): bool
    {
        return $this->check();
    }

    public function lastError(): ?Throwable
    {
        return $this->lastError;
    }
}
