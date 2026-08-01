<?php

declare(strict_types=1);

namespace Support\Tests\Adapter;

use Maniaba\CodeIgniterSse\Contracts\BrokerInterface;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;

final class RecordingBroker implements BrokerInterface
{
    public static int $constructed = 0;

    /**
     * @var list<array{channel: string, event: EventInterface}>
     */
    public array $published = [];

    /**
     * @var list<string>
     */
    public array $channels = [];

    public function __construct()
    {
        self::$constructed++;
    }

    public static function reset(): void
    {
        self::$constructed = 0;
    }

    public function publish(string $channel, EventInterface $event): void
    {
        $this->published[] = ['channel' => $channel, 'event' => $event];
    }

    public function subscribe(
        array $channels,
        callable $onMessage,
        ?callable $shouldStop = null,
        ?callable $onIdle = null,
    ): void {
        $this->channels = $channels;

        if ($onIdle !== null) {
            $onIdle();
        }
    }
}
