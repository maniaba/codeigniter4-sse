<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Event;

use DateTimeImmutable;
use JsonException;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidEventPayloadException;
use Maniaba\CodeIgniterSse\Exception\UnsupportedEventVersionException;
use Throwable;

final class JsonEventSerializer implements SerializerInterface
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    public function serialize(string $channel, EventInterface $event): string
    {
        try {
            return json_encode(new BrokerMessage($channel, $event), self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidEventPayloadException('The SSE event could not be encoded.', 0, $exception);
        }
    }

    public function deserialize(string $payload): BrokerMessage
    {
        try {
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEventPayloadException('The broker payload is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidEventPayloadException('The broker payload must be a JSON object.');
        }

        $version = $decoded['version'] ?? null;

        if (! is_int($version)) {
            throw new InvalidEventPayloadException('The broker payload has no integer version.');
        }

        if ($version !== BrokerMessage::CURRENT_VERSION) {
            throw new UnsupportedEventVersionException(
                sprintf('Unsupported broker event version %d.', $version),
            );
        }

        $id         = $decoded['id'] ?? null;
        $name       = $decoded['event'] ?? null;
        $channel    = $decoded['channel'] ?? null;
        $data       = $decoded['data'] ?? null;
        $occurredAt = $decoded['occurredAt'] ?? null;

        if (! is_string($id) || ! is_string($name) || ! is_string($channel)
                             || ! is_array($data) || ! is_string($occurredAt)) {
            throw new InvalidEventPayloadException('The broker event envelope has invalid field types.');
        }

        try {
            $event = new SseEvent(
                name: $name,
                data: $data,
                id: $id,
                occurredAt: new DateTimeImmutable($occurredAt),
            );

            return new BrokerMessage($channel, $event, $version);
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidEventPayloadException) {
                throw $exception;
            }

            throw new InvalidEventPayloadException('The broker event envelope is invalid.', 0, $exception);
        }
    }
}
