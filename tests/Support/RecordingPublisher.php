<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;

final class RecordingPublisher implements PublisherInterface
{
    /**
     * @var list<array{channel: string, event: EventInterface}>
     */
    public array $published = [];

    public function publish(string $channel, EventInterface $event): void
    {
        $this->published[] = [
            'channel' => $channel,
            'event'   => $event,
        ];
    }
}
