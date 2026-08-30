<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

final readonly class ChannelAuthorizationContext
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        private ?object $user,
        private string $channel,
        private string $pattern,
        private array $parameters = [],
    ) {
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function param(string $name): ?string
    {
        return $this->parameters[$name] ?? null;
    }
}
