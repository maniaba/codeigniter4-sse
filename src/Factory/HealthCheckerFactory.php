<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisHealthChecker;
use Maniaba\CodeIgniterSse\Config\Sse;

final readonly class HealthCheckerFactory
{
    public function __construct(
        private ?RedisConfigFactory $redisConfigs = null,
    ) {
    }

    public function create(Sse $config): RedisHealthChecker
    {
        return new RedisHealthChecker(
            new RedisConnectionFactory(
                ($this->redisConfigs ?? new RedisConfigFactory())->create($config),
            ),
        );
    }
}
