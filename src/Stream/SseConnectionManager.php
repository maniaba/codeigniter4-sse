<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Stream;

use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Event\BrokerMessage;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Throwable;

final readonly class SseConnectionManager
{
    public function __construct(
        private SubscriberInterface $subscriber,
        private EventFactory $events,
        private SseConnectionOptions $options = new SseConnectionOptions(),
        private BrowserEventEncoder $encoder = new BrowserEventEncoder(),
    ) {
    }

    /**
     * @param list<string> $channels
     */
    public function stream(SseOutputInterface $output, array $channels): void
    {
        $startedAt     = microtime(true);
        $lastHeartbeat = $startedAt;
        $state         = new SseConnectionState();
        $encoder       = $this->encoder;

        $state->stopWhen(! $output->retry($this->options->retryMilliseconds));

        if ($state->isStopped()) {
            return;
        }

        if ($this->options->emitConnectedEvent) {
            $connected = new BrokerMessage(
                'sse.system',
                $this->events->create('sse.connected', ['channels' => $channels]),
            );

            $state->stopWhen(! $output->event(
                $this->encoder->encode($connected),
                $connected->event()->name(),
                $connected->id(),
            ));
        }

        if ($state->isStopped()) {
            return;
        }

        try {
            $this->subscriber->subscribe(
                channels: $channels,
                onMessage: static function (BrokerMessage $message) use ($output, $state, $encoder): void {
                    if ($state->isStopped()) {
                        return;
                    }

                    $state->stopWhen(! $output->event(
                        $encoder->encode($message),
                        $message->event()->name(),
                        $message->id(),
                    ));
                },
                shouldStop: fn (): bool => ! $output->isClientConnected()
                        || microtime(true) - $startedAt >= $this->options->maximumConnectionSeconds
                        || $state->isStopped(),
                onIdle: function () use ($output, &$lastHeartbeat, $state): void {
                    if ($state->isStopped()) {
                        return;
                    }

                    $now = microtime(true);

                    if ($now - $lastHeartbeat < $this->options->heartbeatInterval) {
                        return;
                    }

                    $state->stopWhen(
                        ! $output->comment('heartbeat ' . gmdate('Y-m-d\TH:i:s\Z')),
                    );
                    $lastHeartbeat = $now;
                },
            );
        } catch (Throwable $exception) {
            if (function_exists('log_message')) {
                log_message('error', 'SSE subscription stopped: {message}', [
                    'message' => $exception->getMessage(),
                ]);
            }

            if ($output->isClientConnected()) {
                $error = new BrokerMessage(
                    'sse.system',
                    $this->events->create('sse.error', ['retry' => true]),
                );

                $output->event(
                    $this->encoder->encode($error),
                    $error->event()->name(),
                    $error->id(),
                );
            }
        }
    }
}
