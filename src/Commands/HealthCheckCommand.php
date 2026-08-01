<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\HealthCheckableInterface;
use Maniaba\CodeIgniterSse\Health\HealthCheckResult;

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
        $config  = Sse::discover();
        $adapter = service('sseBrokerAdapter', $config, false);

        if (! $adapter instanceof BrokerAdapterInterface) {
            CLI::error('The sseBrokerAdapter service must implement ' . BrokerAdapterInterface::class . '.');

            return EXIT_ERROR;
        }

        if (! $adapter instanceof HealthCheckableInterface) {
            CLI::write(
                sprintf('[SKIPPED] The "%s" SSE broker does not expose a health check.', $config->broker),
                'yellow',
            );

            return EXIT_SUCCESS;
        }

        return $this->render($adapter->healthCheck());
    }

    private function render(HealthCheckResult $result): int
    {
        if ($result->status === HealthCheckResult::FAILED) {
            CLI::error('[FAILED] ' . $result->summary);

            foreach ($result->details as $detail) {
                CLI::error('[INFO] ' . $detail);
            }

            $error = $result->error;

            while ($error !== null) {
                CLI::error($error::class . ': ' . $error->getMessage());
                $error = $error->getPrevious();
            }

            return EXIT_ERROR;
        }

        $color = $result->status === HealthCheckResult::SKIPPED ? 'yellow' : 'green';
        $label = $result->status === HealthCheckResult::SKIPPED ? 'SKIPPED' : 'OK';

        CLI::write(sprintf('[%s] %s', $label, $result->summary), $color);

        foreach ($result->details as $detail) {
            CLI::write('[INFO] ' . $detail, 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
