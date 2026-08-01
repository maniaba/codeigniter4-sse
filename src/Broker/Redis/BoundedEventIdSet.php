<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use SplQueue;

/**
 * Fixed-size insertion-ordered set used to suppress Pub/Sub duplicates caused
 * by overlapping regular and pattern subscriptions.
 *
 * @internal
 */
final class BoundedEventIdSet
{
    /**
     * @var array<string, true>
     */
    private array $keys = [];

    /**
     * @var SplQueue<string>
     */
    private SplQueue $order;

    public function __construct(
        private readonly int $capacity,
    ) {
        $this->order = new SplQueue();
    }

    /**
     * Returns true when the key was already present.
     */
    public function containsOrAdd(string $key): bool
    {
        if (isset($this->keys[$key])) {
            return true;
        }

        $this->keys[$key] = true;
        $this->order->enqueue($key);

        if ($this->order->count() > $this->capacity) {
            unset($this->keys[$this->order->dequeue()]);
        }

        return false;
    }
}
