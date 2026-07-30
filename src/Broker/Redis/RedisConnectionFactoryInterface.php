<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

interface RedisConnectionFactoryInterface
{
    public function create(): RedisConnectionInterface;
}
