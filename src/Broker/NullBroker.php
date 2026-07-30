<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker;

use Maniaba\CodeIgniterSse\Contracts\BrokerInterface;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Support\Channel;

final class NullBroker implements BrokerInterface
{
    public function publish(string $channel, EventInterface $event): void
    {
        Channel::from($channel);
    }

    public function subscribe(
        array $channels,
        callable $onMessage,
        ?callable $shouldStop = null,
        ?callable $onIdle = null,
    ): void {
        foreach ($channels as $channel) {
            Channel::from($channel);
        }

        if ($shouldStop === null) {
            if ($onIdle !== null) {
                $onIdle();
            }

            return;
        }

        while (! $shouldStop()) {
            if ($onIdle !== null) {
                $onIdle();
            }

            usleep(250_000);
        }
    }
}
