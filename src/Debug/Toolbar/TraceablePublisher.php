<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Debug\Toolbar;

use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Throwable;

final class TraceablePublisher implements PublisherInterface
{
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly int $limit = 100,
    ) {
    }

    public function publish(string $channel, EventInterface $event): void
    {
        try {
            $this->publisher->publish($channel, $event);
        } catch (Throwable $error) {
            SseEventHistory::recordFailed(
                $channel,
                $event,
                $this->publisher::class,
                $error,
                $this->limit,
            );

            throw $error;
        }

        SseEventHistory::recordPublished(
            $channel,
            $event,
            $this->publisher::class,
            $this->limit,
        );
    }
}
