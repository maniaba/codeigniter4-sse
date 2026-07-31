<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Factory\HealthCheckerFactory;
use Maniaba\CodeIgniterSse\Factory\MercureConfigFactory;

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

        if ($config->streamTransport() === 'mercure') {
            $mercure = (new MercureConfigFactory())->create($config);

            if (! function_exists('curl_version')) {
                CLI::error('The Mercure publisher requires the PHP cURL extension.');

                return EXIT_ERROR;
            }

            CLI::write(
                sprintf(
                    '[OK] Mercure SSE configuration is valid for %s.',
                    $mercure->hubUrl,
                ),
                'green',
            );
            CLI::write(
                '[INFO] Hub readiness is exposed through the Mercure Caddy admin API and is not queried by this command.',
                'yellow',
            );

            return EXIT_SUCCESS;
        }

        if (strtolower($config->broker) !== 'redis') {
            CLI::write(
                sprintf('[OK] The "%s" SSE broker does not require a network health check.', $config->broker),
                'green',
            );

            return EXIT_SUCCESS;
        }

        $redis   = $config->redis();
        $checker = (new HealthCheckerFactory())->create($config);

        if (! $checker->check()) {
            CLI::error(
                sprintf(
                    'Redis SSE health check failed for %s://%s:%d (database %d).',
                    $redis['scheme'],
                    $redis['host'],
                    $redis['port'],
                    $redis['database'],
                ),
            );

            $error = $checker->lastError();

            while ($error !== null) {
                CLI::error($error::class . ': ' . $error->getMessage());
                $error = $error->getPrevious();
            }

            return EXIT_ERROR;
        }

        CLI::write(
            sprintf(
                '[OK] Redis SSE broker is reachable at %s://%s:%d.',
                $redis['scheme'],
                $redis['host'],
                $redis['port'],
            ),
            'green',
        );

        return EXIT_SUCCESS;
    }
}
