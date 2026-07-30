<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisHealthChecker;
use Maniaba\CodeIgniterSse\Config\Sse;

final class HealthCheckCommand extends BaseCommand
{
    protected $group       = 'SSE';
    protected $name        = 'sse:health-check';
    protected $description = 'Checks the configured SSE broker connection.';
    protected $usage       = 'sse:health-check';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $config = Sse::discover();

        if (strtolower($config->broker) !== 'redis') {
            CLI::write(
                sprintf('[OK] The "%s" SSE broker does not require a network health check.', $config->broker),
                'green',
            );

            return EXIT_SUCCESS;
        }

        /** @var RedisHealthChecker $checker */
        $checker = service('sseRedisHealthChecker');

        if (! $checker->check()) {
            CLI::error(
                sprintf(
                    'Redis SSE health check failed for %s://%s:%d (database %d).',
                    $config->redisScheme,
                    $config->redisHost,
                    $config->redisPort,
                    $config->redisDatabase,
                ),
            );

            return EXIT_ERROR;
        }

        CLI::write(
            sprintf(
                '[OK] Redis SSE broker is reachable at %s://%s:%d.',
                $config->redisScheme,
                $config->redisHost,
                $config->redisPort,
            ),
            'green',
        );

        return EXIT_SUCCESS;
    }
}
