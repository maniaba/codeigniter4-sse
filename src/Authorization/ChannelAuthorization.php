<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;

final readonly class ChannelAuthorization
{
    public function __construct(
        private ChannelAuthorizerInterface $authorizer,
    ) {
    }

    /**
     * @param list<string> $channels
     *
     * @return list<string>
     */
    public function authorizeAll(?object $user, array $channels): array
    {
        foreach ($channels as $channel) {
            if (! $this->authorizer->authorize($user, $channel)) {
                throw new UnauthorizedChannelException('One or more requested channels are not authorized.');
            }
        }

        return $channels;
    }
}
