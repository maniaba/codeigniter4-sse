<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorization;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

final class AuthorizationFactory
{
    public function channelAuthorization(Sse $config): ChannelAuthorization
    {
        return new ChannelAuthorization($this->channelAuthorizer($config));
    }

    public function channelAuthorizer(Sse $config): ChannelAuthorizerInterface
    {
        $authorizer = $this->make($config->channelAuthorizer);

        if (! $authorizer instanceof ChannelAuthorizerInterface) {
            throw new LogicException('The configured channel authorizer must implement ' . ChannelAuthorizerInterface::class . '.');
        }

        return $authorizer;
    }

    public function userResolver(Sse $config): UserResolverInterface
    {
        $resolver = $this->make($config->userResolver);

        if (! $resolver instanceof UserResolverInterface) {
            throw new LogicException('The configured user resolver must implement ' . UserResolverInterface::class . '.');
        }

        return $resolver;
    }

    private function make(string $class): object
    {
        if (class_exists($class)) {
            return new $class();
        }

        throw new LogicException('The configured authorization definition is invalid.');
    }
}
