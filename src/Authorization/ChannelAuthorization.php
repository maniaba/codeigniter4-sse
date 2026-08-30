<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;

final readonly class ChannelAuthorization
{
    public function __construct(
        private ChannelRegistry $channels,
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
            $match = $this->channels->match($channel);

            if ($match === null || ! $match->definition()->authorize($match->context($user))) {
                throw new UnauthorizedChannelException('One or more requested channels are not authorized.');
            }
        }

        return $channels;
    }
}
