<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Event;

use DateTimeImmutable;
use Maniaba\CodeIgniterSse\Contracts\EventIdGeneratorInterface;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Support\UuidV7Generator;

final readonly class EventFactory
{
    public function __construct(
        private EventIdGeneratorInterface $idGenerator = new UuidV7Generator(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(
        string $name,
        array $data = [],
        ?string $id = null,
        ?DateTimeImmutable $occurredAt = null,
    ): EventInterface {
        return new SseEvent(
            name: $name,
            data: $data,
            id: $id ?? $this->idGenerator->generate(),
            occurredAt: $occurredAt,
        );
    }
}
