<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\InMemory;

use Maniaba\CodeIgniterSse\Contracts\BrokerInterface;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Event\BrokerMessage;
use Maniaba\CodeIgniterSse\Support\Channel;

/**
 * Deterministic, process-local broker intended for tests.
 */
final class InMemoryBroker implements BrokerInterface
{
    /**
     * @var list<BrokerMessage>
     */
    private array $messages = [];

    public function publish(string $channel, EventInterface $event): void
    {
        $this->messages[] = new BrokerMessage(Channel::from($channel)->value(), $event);
    }

    public function subscribe(
        array $channels,
        callable $onMessage,
        ?callable $shouldStop = null,
        ?callable $onIdle = null,
    ): void {
        $allowed = [];
        $cursor  = 0;

        foreach ($channels as $channel) {
            $name           = Channel::from($channel)->value();
            $allowed[$name] = true;
        }

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                return;
            }

            while (isset($this->messages[$cursor])) {
                $message = $this->messages[$cursor++];

                if (! isset($allowed[$message->channel()])) {
                    continue;
                }

                $onMessage($message);
            }

            if ($onIdle !== null) {
                $onIdle();
            }

            if ($shouldStop === null) {
                return;
            }

            usleep(250_000);
        }
    }
}
