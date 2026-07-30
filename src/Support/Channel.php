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

    public static function publicChannel(string $name): self
    {
        return new self('public.' . self::segment($name));
    }

    public static function user(int|string $userId): self
    {
        return new self('users.' . self::segment($userId));
    }

    public static function tenant(int|string $tenantId, ?string $suffix = null): self
    {
        return self::withOptionalSuffix('tenants.' . self::segment($tenantId), $suffix);
    }

    public static function order(int|string $orderId): self
    {
        return new self('orders.' . self::segment($orderId));
    }

    public static function project(int|string $projectId, ?string $suffix = null): self
    {
        return self::withOptionalSuffix('projects.' . self::segment($projectId), $suffix);
    }

    public static function role(string $role): self
    {
        return new self('roles.' . self::segment($role));
    }

    public function value(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    private static function withOptionalSuffix(string $base, ?string $suffix): self
    {
        if ($suffix === null || trim($suffix) === '') {
            return new self($base);
        }

        return new self($base . '.' . self::segment($suffix));
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
