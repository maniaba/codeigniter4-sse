<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Event\BrokerMessage;

final class RecordingSubscriber implements SubscriberInterface
{
    /**
     * @var list<string>
     */
    public array $channels = [];

    /**
     * @param list<BrokerMessage> $messages
     */
    public function __construct(private array $messages = [])
    {
    }

    public function subscribe(
        array $channels,
        callable $onMessage,
        ?callable $shouldStop = null,
        ?callable $onIdle = null,
    ): void {
        $this->channels = $channels;

        foreach ($this->messages as $message) {
            if ($shouldStop !== null && $shouldStop()) {
                return;
            }

            $onMessage($message);
        }

        if ($onIdle !== null && ($shouldStop === null || ! $shouldStop())) {
            $onIdle();
        }
    }
}
