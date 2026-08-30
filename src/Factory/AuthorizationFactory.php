<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorization;
use Maniaba\CodeIgniterSse\Authorization\ChannelRegistry;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

final class AuthorizationFactory
{
    public function channelAuthorization(Sse $config): ChannelAuthorization
    {
        return new ChannelAuthorization($this->channelRegistry($config));
    }

    public function channelRegistry(Sse $config): ChannelRegistry
    {
        return new ChannelRegistry($this->channelDefinitions($config));
    }

    /**
     * @return list<ChannelDefinitionInterface>
     */
    public function channelDefinitions(Sse $config): array
    {
        $definitions = [];

        foreach ($config->channels as $definition) {
            $definitions[] = $this->channelDefinition($definition);
        }

        return $definitions;
    }

    public function userResolver(Sse $config): UserResolverInterface
    {
        $resolver = $this->make($config->userResolver);

        if (! $resolver instanceof UserResolverInterface) {
            throw new LogicException('The configured user resolver must implement ' . UserResolverInterface::class . '.');
        }

        return $resolver;
    }

    private function channelDefinition(mixed $definition): ChannelDefinitionInterface
    {
        if ($definition instanceof ChannelDefinitionInterface) {
            return $definition;
        }

        if (is_callable($definition)) {
            $resolved = $definition();

            if (! $resolved instanceof ChannelDefinitionInterface) {
                throw new LogicException(
                    'The configured SSE channel definition factory must return ' . ChannelDefinitionInterface::class . '.',
                );
            }

            return $resolved;
        }

        if (is_string($definition)) {
            if (! class_exists($definition)) {
                throw new LogicException(
                    sprintf('The configured SSE channel definition "%s" does not exist.', $definition),
                );
            }

            $resolved = new $definition();

            if (! $resolved instanceof ChannelDefinitionInterface) {
                throw new LogicException(
                    sprintf(
                        'The configured SSE channel definition "%s" must implement %s.',
                        $definition,
                        ChannelDefinitionInterface::class,
                    ),
                );
            }

            return $resolved;
        }

        throw new LogicException(
            'The configured SSE channel definition must be a class name, factory, or ' . ChannelDefinitionInterface::class . '.',
        );
    }

    private function make(string $class): object
    {
        if (class_exists($class)) {
            return new $class();
        }

        throw new LogicException('The configured authorization definition is invalid.');
    }
}
