<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisCommandException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisPublishException;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Support\Channel;
use Throwable;

final class RedisPublisher implements PublisherInterface
{
    private ?RedisConnectionInterface $connection = null;

    public function __construct(
        private readonly RedisConfig $config,
        private readonly SerializerInterface $serializer,
        private readonly RedisConnectionFactoryInterface $connectionFactory,
    ) {
    }

    public function publish(string $channel, EventInterface $event): void
    {
        $channel = Channel::from($channel)->value();
        $payload = $this->serializer->serialize($channel, $event);

        if (\strlen($payload) > $this->config->maxPayloadBytes) {
            throw new RedisPublishException(
                sprintf(
                    'The serialized SSE event exceeds the Redis payload limit of %d bytes.',
                    $this->config->maxPayloadBytes,
                ),
            );
        }

        $redisChannel = $this->config->channelPrefix . $channel;
        $attempt      = 0;

        while (true) {
            try {
                $connection = $this->connection();
                $connection->publish($redisChannel, $payload);

                return;
            } catch (RedisConnectionException $exception) {
                $this->disconnect();

                if ($attempt >= $this->config->maxReconnectAttempts) {
                    throw new RedisPublishException(
                        'Unable to publish the SSE event after reconnecting to Redis.',
                        0,
                        $exception,
                    );
                }

                $attempt++;
                $this->delayReconnect();
            } catch (RedisCommandException $exception) {
                throw new RedisPublishException('Redis rejected the SSE publish command.', 0, $exception);
            } catch (Throwable $exception) {
                $this->disconnect();

                throw new RedisPublishException('Unable to publish the SSE event.', 0, $exception);
            }
        }
    }

    public function close(): void
    {
        $this->disconnect();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function connection(): RedisConnectionInterface
    {
        $this->connection ??= $this->connectionFactory->create();

        if (! $this->connection->isConnected()) {
            $this->connection->connect();
        }

        return $this->connection;
    }

    private function disconnect(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    private function delayReconnect(): void
    {
        if ($this->config->reconnectDelayMilliseconds > 0) {
            \usleep($this->config->reconnectDelayMilliseconds * 1000);
        }
    }
}
