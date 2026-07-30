<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Contracts\EventIdGeneratorInterface;

final readonly class FixedEventIdGenerator implements EventIdGeneratorInterface
{
    public function __construct(private string $id = '0198-0000-test-id')
    {
    }

    public function generate(): string
    {
        return $this->id;
    }
}
