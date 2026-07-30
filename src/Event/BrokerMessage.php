<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Event;

use JsonSerializable;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Support\Channel;

final readonly class BrokerMessage implements JsonSerializable
{
    public const CURRENT_VERSION = 1;

    private string $channel;

    public function __construct(
        string $channel,
        private EventInterface $event,
        private int $version = self::CURRENT_VERSION,
    ) {
        $this->channel = Channel::from($channel)->value();
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function event(): EventInterface
    {
        return $this->event;
    }

    public function id(): string
    {
        return $this->event->id();
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return array{
     *     id: string,
     *     event: string,
     *     channel: string,
     *     data: array<string, mixed>,
     *     occurredAt: string,
     *     version: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->event->id(),
            'event'      => $this->event->name(),
            'channel'    => $this->channel,
            'data'       => $this->event->data(),
            'occurredAt' => $this->event->occurredAt()->format(DATE_ATOM),
            'version'    => $this->version,
        ];
    }
}
