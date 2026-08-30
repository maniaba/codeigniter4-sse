<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final readonly class ChannelMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        private ChannelDefinitionInterface $definition,
        private string $channel,
        private string $pattern,
        private array $parameters,
    ) {
    }

    public function definition(): ChannelDefinitionInterface
    {
        return $this->definition;
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

    public function context(?object $user): ChannelAuthorizationContext
    {
        return new ChannelAuthorizationContext(
            user: $user,
            channel: $this->channel,
            pattern: $this->pattern,
            parameters: $this->parameters,
        );
    }
}
