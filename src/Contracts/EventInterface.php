<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use DateTimeImmutable;
use JsonSerializable;

interface EventInterface extends JsonSerializable
{
    public function id(): string;

    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;

    public function occurredAt(): DateTimeImmutable;
}
