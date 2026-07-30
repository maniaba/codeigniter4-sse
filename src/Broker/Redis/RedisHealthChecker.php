<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Throwable;

final readonly class RedisHealthChecker
{
    public function __construct(
        private RedisConnectionFactoryInterface $connectionFactory,
    ) {
    }

    public function check(): bool
    {
        $connection = null;

        try {
            $connection = $this->connectionFactory->create();
            $connection->connect();

            return $connection->ping();
        } catch (Throwable) {
            return false;
        } finally {
            $connection?->close();
        }
    }

    public function isHealthy(): bool
    {
        return $this->check();
    }
}
