<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Debug\Toolbar;

use JsonException;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Throwable;

final class SseEventHistory
{
    /**
     * @var list<array{
     *     time: float,
     *     datetime: string,
     *     status: 'failed'|'published',
     *     channel: string,
     *     event: string,
     *     id: string,
     *     occurredAt: string,
     *     dataKeys: list<string>,
     *     payloadBytes: int,
     *     publisher: string,
     *     error: string|null
     * }>
     */
    private static array $events = [];

    public static function recordPublished(
        string $channel,
        EventInterface $event,
        string $publisher,
        int $limit,
    ): void {
        self::record($channel, $event, $publisher, 'published', null, $limit);
    }

    public static function recordFailed(
        string $channel,
        EventInterface $event,
        string $publisher,
        Throwable $error,
        int $limit,
    ): void {
        self::record($channel, $event, $publisher, 'failed', self::errorChain($error), $limit);
    }

    /**
     * @return list<array{
     *     time: float,
     *     datetime: string,
     *     status: 'failed'|'published',
     *     channel: string,
     *     event: string,
     *     id: string,
     *     occurredAt: string,
     *     dataKeys: list<string>,
     *     payloadBytes: int,
     *     publisher: string,
     *     error: string|null
     * }>
     */
    public static function all(): array
    {
        return self::$events;
    }

    public static function count(): int
    {
        return count(self::$events);
    }

    public static function clear(): void
    {
        self::$events = [];
    }

    /**
     * @param 'failed'|'published' $status
     */
    private static function record(
        string $channel,
        EventInterface $event,
        string $publisher,
        string $status,
        ?string $error,
        int $limit,
    ): void {
        $limit = max(1, $limit);

        self::$events[] = [
            'time'         => microtime(true),
            'datetime'     => date('H:i:s'),
            'status'       => $status,
            'channel'      => $channel,
            'event'        => $event->name(),
            'id'           => $event->id(),
            'occurredAt'   => $event->occurredAt()->format(DATE_ATOM),
            'dataKeys'     => self::dataKeys($event->data()),
            'payloadBytes' => self::payloadBytes($event),
            'publisher'    => $publisher,
            'error'        => $error,
        ];

        if (count(self::$events) > $limit) {
            self::$events = array_slice(self::$events, -$limit);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function dataKeys(array $data): array
    {
        return array_values(array_filter(
            array_map(
                static fn (int|string $key): string => (string) $key,
                array_keys($data),
            ),
            static fn (string $key): bool => $key !== '',
        ));
    }

    private static function payloadBytes(EventInterface $event): int
    {
        try {
            return strlen(json_encode($event, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return 0;
        }
    }

    private static function errorChain(Throwable $error): string
    {
        $messages = [];

        do {
            $message = $error::class . ': ' . $error->getMessage();

            if (! in_array($message, $messages, true)) {
                $messages[] = $message;
            }

            $error = $error->getPrevious();
        } while ($error instanceof Throwable);

        return implode(' <- ', $messages);
    }
}
