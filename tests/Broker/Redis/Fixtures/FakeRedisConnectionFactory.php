<?php

declare(strict_types=1);

namespace Tests\Broker\Redis\Fixtures;

use LogicException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactoryInterface;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionInterface;

final class FakeRedisConnectionFactory implements RedisConnectionFactoryInterface
{
    public int $createCalls = 0;

    /**
     * @param list<RedisConnectionInterface> $connections
     */
    public function __construct(
        private array $connections,
    ) {
    }

    public function create(): RedisConnectionInterface
    {
        $this->createCalls++;
        $connection = array_shift($this->connections);

        if ($connection === null) {
            throw new LogicException('No fake Redis connection remains.');
        }

        return $connection;
    }
}
