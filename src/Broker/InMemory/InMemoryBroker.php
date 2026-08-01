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

        foreach ($channels as $channel) {
            $name           = Channel::from($channel)->value();
            $allowed[$name] = true;
        }

        foreach ($this->messages as $message) {
            if ($shouldStop !== null && $shouldStop()) {
                return;
            }

            if (! isset($allowed[$message->channel()])) {
                continue;
            }

            $onMessage($message);
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

    /**
     * @return list<BrokerMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
