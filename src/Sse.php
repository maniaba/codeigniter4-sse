<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublishableEventInterface;
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
        Channel|PublishableEventInterface|string $channel,
        EventInterface|string|null $event = null,
        array $data = [],
    ): EventInterface {
        if ($channel instanceof PublishableEventInterface) {
            if ($event !== null || $data !== []) {
                throw new InvalidArgumentException(
                    'The event and data arguments must be omitted when publishing a PublishableEventInterface instance.',
                );
            }

            return $this->publish($channel->channel(), $channel->event(), $channel->data());
        }

        if ($event === null) {
            throw new InvalidArgumentException(
                'The event argument is required when publishing by channel.',
            );
        }

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
