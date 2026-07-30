<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Support;

use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Stringable;

final readonly class Channel implements Stringable
{
    private const MAX_LENGTH = 200;

    private string $name;

    public function __construct(string $name)
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > self::MAX_LENGTH) {
            throw new InvalidChannelException(
                sprintf('A channel name must contain between 1 and %d bytes.', self::MAX_LENGTH),
            );
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)*$/D', $name) !== 1) {
            throw new InvalidChannelException(
                'Channel names may contain alphanumeric segments, dashes and underscores separated by dots.',
            );
        }

        $this->name = $name;
    }

    public static function from(self|string $channel): self
    {
        return $channel instanceof self ? $channel : new self($channel);
    }

    public static function join(int|string ...$segments): self
    {
        return new self(implode('.', array_map(self::segment(...), $segments)));
    }

    public function value(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    private static function segment(int|string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $value) !== 1) {
            throw new InvalidChannelException('A channel segment contains unsupported characters.');
        }

        return $value;
    }
}
