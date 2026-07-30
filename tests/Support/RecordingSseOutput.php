<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;

final class RecordingSseOutput implements SseOutputInterface
{
    /**
     * @var list<array{data: string, event: ?string, id: ?string}>
     */
    public array $events = [];

    /**
     * @var list<string>
     */
    public array $comments = [];

    /**
     * @var list<int>
     */
    public array $retries = [];

    public bool $connected = true;

    public function event(string $data, ?string $event = null, ?string $id = null): bool
    {
        $this->events[] = compact('data', 'event', 'id');

        return $this->connected;
    }

    public function comment(string $text): bool
    {
        $this->comments[] = $text;

        return $this->connected;
    }

    public function retry(int $milliseconds): bool
    {
        $this->retries[] = $milliseconds;

        return $this->connected;
    }

    public function isClientConnected(): bool
    {
        return $this->connected;
    }
}
