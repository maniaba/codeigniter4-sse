<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final readonly class ChannelRegistry
{
    /**
     * @var list<array{definition: ChannelDefinitionInterface, pattern: ChannelPattern, order: int}>
     */
    private array $entries;

    /**
     * @param list<ChannelDefinitionInterface> $definitions
     */
    public function __construct(array $definitions)
    {
        $entries = [];
        $seen    = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof ChannelDefinitionInterface) {
                throw new InvalidArgumentException(
                    'Every registered SSE channel must implement ' . ChannelDefinitionInterface::class . '.',
                );
            }

            $pattern = new ChannelPattern($definition::pattern());

            if (isset($seen[$pattern->signature()])) {
                throw new InvalidArgumentException(
                    sprintf('SSE channel pattern "%s" is registered more than once.', $pattern->value()),
                );
            }

            $seen[$pattern->signature()] = true;
            $entries[]                   = [
                'definition' => $definition,
                'pattern'    => $pattern,
                'order'      => count($entries),
            ];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => $right['pattern']->specificity() <=> $left['pattern']->specificity()
                ?: $left['order'] <=> $right['order'],
        );

        $this->entries = $entries;
    }

    public function match(string $channel): ?ChannelMatch
    {
        foreach ($this->entries as $entry) {
            $parameters = $entry['pattern']->match($channel);

            if ($parameters === null) {
                continue;
            }

            return new ChannelMatch(
                definition: $entry['definition'],
                channel: $channel,
                pattern: $entry['pattern']->value(),
                parameters: $parameters,
            );
        }

        return null;
    }

    public function has(string $channel): bool
    {
        return $this->match($channel) !== null;
    }
}
