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
    private array $ids = [];

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
     * Returns true when the ID was already present.
     */
    public function containsOrAdd(string $id): bool
    {
        if (isset($this->ids[$id])) {
            return true;
        }

        $this->ids[$id] = true;
        $this->order->enqueue($id);

        if ($this->order->count() > $this->capacity) {
            unset($this->ids[$this->order->dequeue()]);
        }

        return false;
    }
}
