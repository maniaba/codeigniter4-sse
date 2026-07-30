<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface SseOutputInterface
{
    public function event(string $data, ?string $event = null, ?string $id = null): bool;

    public function comment(string $text): bool;

    public function retry(int $milliseconds): bool;

    public function isClientConnected(): bool;
}
