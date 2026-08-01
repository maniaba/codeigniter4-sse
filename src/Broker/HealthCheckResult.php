<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker;

use InvalidArgumentException;
use Throwable;

final readonly class HealthCheckResult
{
    public const OK      = 'ok';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    /**
     * @param list<string> $details
     */
    private function __construct(
        public string $status,
        public string $summary,
        public array $details = [],
        public ?Throwable $error = null,
    ) {
        if (! in_array($status, [self::OK, self::FAILED, self::SKIPPED], true)) {
            throw new InvalidArgumentException(sprintf('Unknown SSE health check status "%s".', $status));
        }
    }

    /**
     * @param list<string> $details
     */
    public static function ok(string $summary, array $details = []): self
    {
        return new self(self::OK, $summary, $details);
    }

    /**
     * @param list<string> $details
     */
    public static function failed(string $summary, ?Throwable $error = null, array $details = []): self
    {
        return new self(self::FAILED, $summary, $details, $error);
    }

    /**
     * @param list<string> $details
     */
    public static function skipped(string $summary, array $details = []): self
    {
        return new self(self::SKIPPED, $summary, $details);
    }

    public function isSuccessful(): bool
    {
        return $this->status !== self::FAILED;
    }
}
