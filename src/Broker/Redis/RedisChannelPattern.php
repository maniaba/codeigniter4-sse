<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Stringable;

final readonly class RedisChannelPattern implements Stringable
{
    private string $pattern;

    public function __construct(string $pattern)
    {
        $pattern = trim($pattern);

        if (
            $pattern === ''
            || strlen($pattern) > 200
            || str_contains($pattern, '..')
            || str_starts_with($pattern, '.')
            || str_ends_with($pattern, '.')
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.*?\[\]\^\\\\-]*$/D', $pattern) !== 1
        ) {
            throw new InvalidChannelException('The Redis channel pattern is invalid.');
        }

        $this->pattern = $pattern;
    }

    public function value(): string
    {
        return $this->pattern;
    }

    public function __toString(): string
    {
        return $this->pattern;
    }
}
