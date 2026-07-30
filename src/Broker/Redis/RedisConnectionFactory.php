<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Closure;

final class RedisConnectionFactory implements RedisConnectionFactoryInterface
{
    private readonly ?Closure $connector;

    public function __construct(
        private readonly RedisConfig $config,
        ?callable $connector = null,
    ) {
        $this->connector = $connector === null ? null : Closure::fromCallable($connector);
    }

    public function create(): RedisConnectionInterface
    {
        return new SocketRedisConnection($this->config, $this->connector);
    }
}
