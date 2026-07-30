<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Support\Channel;

final readonly class Sse
{
    public function __construct(
        private PublisherInterface $publisher,
        private EventFactory $events = new EventFactory(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publish(
        Channel|string $channel,
        EventInterface|string $event,
        array $data = [],
    ): EventInterface {
        if ($event instanceof EventInterface) {
            if ($data !== []) {
                throw new InvalidArgumentException(
                    'The data argument must be empty when publishing an EventInterface instance.',
                );
            }

            $resolvedEvent = $event;
        } else {
            $resolvedEvent = $this->events->create($event, $data);
        }

        $this->publisher->publish(Channel::from($channel)->value(), $resolvedEvent);

        return $resolvedEvent;
    }
}
