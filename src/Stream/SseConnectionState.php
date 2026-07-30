<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Stream;

/**
 * Mutable state shared by callbacks during one streamed response.
 *
 * @internal
 */
final class SseConnectionState
{
    private bool $stopped = false;

    public function stopWhen(bool $failed): void
    {
        if ($failed) {
            $this->stopped = true;
        }
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
