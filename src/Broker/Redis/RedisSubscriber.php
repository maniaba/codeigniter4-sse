<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Closure;
use Exception;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisCommandException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisSubscriptionException;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Event\BrokerMessage;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Support\Channel;

final class RedisSubscriber implements SubscriberInterface
{
    private bool $subscribing = false;

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    public function __construct(
        private readonly RedisConfig $config,
        private readonly SerializerInterface $serializer,
        private readonly RedisConnectionFactoryInterface $connectionFactory,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null
            ? static fn (): float => \microtime(true)
            : static fn (): float => (float) $clock();
    }

    public function subscribe(
        array $channels,
        callable $onMessage,
        ?callable $shouldStop = null,
        ?callable $onIdle = null,
    ): void {
        if ($this->subscribing) {
            throw new RedisSubscriptionException('This Redis subscriber is already active.');
        }

        [$redisChannels, $redisPatterns] = $this->prepareSubscriptions($channels);
        $seenIds                         = new BoundedEventIdSet($this->config->deduplicationCapacity);
        $connection                      = null;
        $reconnectAttempts               = 0;
        $this->subscribing               = true;

        try {
            while (! $this->shouldStop($shouldStop)) {
                try {
                    $connection = $this->connectionFactory->create();
                    $connection->connect();
                    $connection->subscribe($redisChannels, $redisPatterns);
                    $lastRedisActivity = ($this->clock)();

                    while (! $this->shouldStop($shouldStop)) {
                        $redisMessage = $connection->readMessage($this->config->pollIntervalSeconds);

                        if ($redisMessage === null) {
                            if ($onIdle !== null) {
                                $onIdle();
                            }

                            $now = ($this->clock)();

                            if (
                                $now - $lastRedisActivity >= $this->config->subscriberPingIntervalSeconds
                            ) {
                                if (! $connection->ping()) {
                                    throw new RedisConnectionException(
                                        'Redis subscription health check failed.',
                                    );
                                }

                                $lastRedisActivity = $now;
                                $reconnectAttempts = 0;
                            }

                            continue;
                        }

                        $lastRedisActivity = ($this->clock)();
                        $reconnectAttempts = 0;
                        $message           = $this->deserialize($redisMessage);

                        if ($message !== null && ! $seenIds->containsOrAdd($message->id())) {
                            $onMessage($message);
                        }

                        if ($onIdle !== null) {
                            $onIdle();
                        }
                    }
                } catch (RedisConnectionException $exception) {
                    if ($this->shouldStop($shouldStop)) {
                        return;
                    }
                    if ($reconnectAttempts >= $this->config->maxReconnectAttempts) {
                        throw new RedisSubscriptionException(
                            'Unable to restore the Redis SSE subscription.',
                            0,
                            $exception,
                        );
                    }

                    $reconnectAttempts++;
                    $this->delayReconnect();
                } catch (RedisCommandException $exception) {
                    throw new RedisSubscriptionException('Redis rejected the SSE subscription.', 0, $exception);
                } finally {
                    if ($connection !== null) {
                        $connection->close();
                        $connection = null;
                    }
                }
            }
        } finally {
            $this->subscribing = false;
        }
    }

    /**
     * @param list<string> $channels
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function prepareSubscriptions(array $channels): array
    {
        if ($channels === []) {
            throw new RedisSubscriptionException('At least one Redis SSE channel is required.');
        }

        $regular  = [];
        $patterns = [];

        foreach ($channels as $channel) {
            if (! is_string($channel)) {
                throw new InvalidChannelException('Redis SSE channel names must be strings.');
            }

            if ($this->isPattern($channel)) {
                $channel                                           = (new RedisChannelPattern($channel))->value();
                $patterns[$this->config->channelPrefix . $channel] = true;

                continue;
            }

            $channel                                          = Channel::from($channel)->value();
            $regular[$this->config->channelPrefix . $channel] = true;
        }

        return [\array_keys($regular), \array_keys($patterns)];
    }

    private function isPattern(string $channel): bool
    {
        $isPattern = \strpbrk($channel, '*?[') !== false;

        if ($isPattern && ! $this->config->allowPatternSubscriptions) {
            throw new InvalidChannelException('Redis pattern subscriptions are disabled.');
        }

        return $isPattern;
    }

    private function deserialize(RedisSubscriptionMessage $redisMessage): ?BrokerMessage
    {
        try {
            $message = $this->serializer->deserialize($redisMessage->payload);
        } catch (Exception) {
            // Pub/Sub is an untrusted boundary. A malformed envelope must not
            // terminate every active browser stream.
            return null;
        }

        $prefix = $this->config->channelPrefix;

        if (! \str_starts_with($redisMessage->channel, $prefix)) {
            return null;
        }

        $logicalChannel = \substr($redisMessage->channel, \strlen($prefix));

        return $message->channel() === $logicalChannel ? $message : null;
    }

    /**
     * @phpstan-impure
     */
    private function shouldStop(?callable $shouldStop): bool
    {
        return $shouldStop !== null && $shouldStop();
    }

    private function delayReconnect(): void
    {
        if ($this->config->reconnectDelayMilliseconds > 0) {
            \usleep($this->config->reconnectDelayMilliseconds * 1000);
        }
    }
}
