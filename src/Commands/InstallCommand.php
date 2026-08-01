<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class InstallCommand extends BaseCommand
{
    protected $group       = 'SSE';
    protected $name        = 'sse:install';
    protected $description = 'Publishes the CodeIgniter SSE config and browser client.';
    protected $usage       = 'sse:install [--force] [--no-assets]';
    protected $options     = [
        '--force'     => 'Overwrite files that already exist.',
        '--no-assets' => 'Publish only the PHP configuration.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $force    = $this->hasOption($params, 'force');
        $noAssets = $this->hasOption($params, 'no-assets');
        $root     = dirname(__DIR__, 2);
        $failed   = false;

        CLI::write('CodeIgniter SSE installer', 'yellow');

        if (! $this->publish(
            $root . '/resources/Config/Sse.php',
            APPPATH . 'Config/Sse.php',
            $force,
        )) {
            $failed = true;
        }

        if (! $noAssets) {
            if (! $this->publish(
                $root . '/resources/js/sse-client.js',
                FCPATH . 'vendor/codeigniter4-sse/sse-client.js',
                $force,
            )) {
                $failed = true;
            }

            if (! $this->publish(
                $root . '/resources/js/sse-client.d.ts',
                FCPATH . 'vendor/codeigniter4-sse/sse-client.d.ts',
                $force,
            )) {
                $failed = true;
            }

            $adapterFiles = glob($root . '/resources/js/adapters/*.{js,d.ts}', GLOB_BRACE);

            if ($adapterFiles === false) {
                CLI::error('Unable to read browser adapter resources.');
                $failed = true;
            } else {
                foreach ($adapterFiles as $adapterFile) {
                    if (! $this->publish(
                        $adapterFile,
                        FCPATH . 'vendor/codeigniter4-sse/adapters/' . basename($adapterFile),
                        $force,
                    )) {
                        $failed = true;
                    }
                }
            }
        }

        return $failed ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function hasOption(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }

    private function publish(string $source, string $target, bool $force): bool
    {
        if (! is_file($source)) {
            CLI::error('Missing package resource: ' . $source);

            return false;
        }

        if (is_file($target) && ! $force) {
            CLI::write('[SKIPPED] ' . $target . ' already exists.', 'yellow');

            return true;
        }

        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            CLI::error('Unable to create directory: ' . $directory);

            return false;
        }

        if (! copy($source, $target)) {
            CLI::error('Unable to publish: ' . $target);

            return false;
        }

        CLI::write('[PUBLISHED] ' . $target, 'green');

        return true;
    }
}
