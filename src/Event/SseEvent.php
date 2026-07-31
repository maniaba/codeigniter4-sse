<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Event;

use DateTimeImmutable;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidEventException;
use Maniaba\CodeIgniterSse\Support\UuidV7Generator;

final readonly class SseEvent implements EventInterface
{
    private string $id;
    private DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $name,
        private array $data = [],
        ?string $id = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,127}$/D', $name) !== 1) {
            throw new InvalidEventException(
                'Event names must start with a letter and contain at most 128 safe characters.',
            );
        }

        $id ??= (new UuidV7Generator())->generate();

        if ($id === '' || strlen($id) > 128 || strpbrk($id, "\r\n\0") !== false) {
            throw new InvalidEventException('Event IDs must be non-empty, single-line strings up to 128 bytes.');
        }

        $this->id         = $id;
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array{id: string, event: string, data: array<string, mixed>, occurredAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'event'      => $this->name,
            'data'       => $this->data,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
