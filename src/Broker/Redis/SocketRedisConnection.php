<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Closure;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisCommandException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisProtocolException;
use SplQueue;
use Throwable;

/**
 * Minimal Redis RESP2 connection intended for dedicated Publisher or Subscriber use.
 */
final class SocketRedisConnection implements RedisConnectionInterface
{
    /**
     * @var resource|null
     */
    private mixed $socket = null;

    private bool $subscribed = false;

    /**
     * Absolute monotonic-ish deadline used to bound one complete RESP frame.
     */
    private ?float $readDeadline = null;

    /**
     * @var SplQueue<RedisSubscriptionMessage>
     */
    private SplQueue $pendingMessages;

    public function __construct(
        private readonly RedisConfig $config,
        private readonly ?Closure $connector = null,
    ) {
        $this->pendingMessages = new SplQueue();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $this->close();

        try {
            $socket = $this->connector === null
                ? $this->openSocket()
                : ($this->connector)(
                    $this->config->endpoint(),
                    $this->config->connectTimeout,
                    $this->config->streamContext,
                );
        } catch (Throwable $exception) {
            throw new RedisConnectionException('Unable to connect to Redis.', 0, $exception);
        }

        if (! \is_resource($socket)) {
            throw new RedisConnectionException('Redis socket connector did not return a stream resource.');
        }

        $this->socket = $socket;
        \stream_set_blocking($this->socket, true);
        $this->setStreamTimeout($this->config->readTimeout);

        try {
            $this->authenticate();
            $this->selectDatabase();
            $this->setClientName();

            if (! $this->ping()) {
                throw new RedisConnectionException('Redis did not respond to PING with PONG.');
            }
        } catch (Throwable $exception) {
            $this->close();

            if ($exception instanceof RedisCommandException || $exception instanceof RedisConnectionException) {
                throw $exception;
            }

            throw new RedisConnectionException('Unable to initialize the Redis connection.', 0, $exception);
        }
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }

        $this->socket          = null;
        $this->subscribed      = false;
        $this->pendingMessages = new SplQueue();
    }

    public function isConnected(): bool
    {
        return \is_resource($this->socket) && ! \feof($this->socket);
    }

    public function ping(): bool
    {
        if ($this->subscribed) {
            return $this->pingSubscribedConnection();
        }

        $response = $this->command(['PING']);

        return $response === 'PONG';
    }

    public function publish(string $channel, string $payload): int
    {
        if ($this->subscribed) {
            throw new RedisCommandException('A subscribed Redis connection cannot be used to publish.');
        }

        $response = $this->command(['PUBLISH', $channel, $payload]);

        if (! \is_int($response)) {
            throw new RedisProtocolException(
                'Redis returned an invalid PUBLISH response of type ' . \get_debug_type($response) . '.',
            );
        }

        return $response;
    }

    public function subscribe(array $channels, array $patterns = []): void
    {
        if ($channels === [] && $patterns === []) {
            throw new RedisCommandException('At least one Redis channel or pattern is required.');
        }

        $this->ensureConnected();

        if ($channels !== []) {
            $this->writeCommand(['SUBSCRIBE', ...$channels]);
        }
        if ($patterns !== []) {
            $this->writeCommand(['PSUBSCRIBE', ...$patterns]);
        }

        $pendingAcknowledgements = [];

        foreach ($channels as $channel) {
            $pendingAcknowledgements['subscribe:' . $channel] = true;
        }

        foreach ($patterns as $pattern) {
            $pendingAcknowledgements['psubscribe:' . $pattern] = true;
        }

        while (\count($pendingAcknowledgements) > 0) {
            $deadline = \microtime(true) + $this->config->readTimeout;

            if (! $this->waitForReadable($this->config->readTimeout)) {
                throw new RedisConnectionException('Timed out while waiting for Redis subscription acknowledgement.');
            }

            $response = $this->readResponseUntil($deadline);
            $type     = self::pubSubType($response);

            if ($type === 'subscribe' || $type === 'psubscribe') {
                $key = $this->subscriptionAcknowledgementKey($response, $type);

                if (! isset($pendingAcknowledgements[$key])) {
                    throw new RedisProtocolException('Redis returned an unexpected subscription acknowledgement.');
                }

                unset($pendingAcknowledgements[$key]);

                continue;
            }

            $message = $this->toSubscriptionMessage($response);

            if ($message !== null) {
                $this->pendingMessages->enqueue($message);
            }
        }

        $this->subscribed = true;
    }

    public function readMessage(float $timeoutSeconds): ?RedisSubscriptionMessage
    {
        if ($timeoutSeconds <= 0.0) {
            throw new RedisConnectionException('Redis message timeout must be greater than zero.');
        }
        if (! $this->subscribed) {
            throw new RedisCommandException('Redis connection is not subscribed.');
        }
        if (! $this->pendingMessages->isEmpty()) {
            return $this->pendingMessages->dequeue();
        }

        $deadline = \microtime(true) + $timeoutSeconds;

        do {
            $remaining = $deadline - \microtime(true);

            if ($remaining <= 0.0 || ! $this->waitForReadable($remaining)) {
                return null;
            }

            $message = $this->toSubscriptionMessage($this->readResponseUntil($deadline));

            if ($message !== null) {
                return $message;
            }
        } while (\microtime(true) < $deadline);

        return null;
    }

    /**
     * @return resource
     */
    private function openSocket(): mixed
    {
        $errorCode    = 0;
        $errorMessage = '';
        $socket       = @\stream_socket_client(
            $this->config->endpoint(),
            $errorCode,
            $errorMessage,
            $this->config->connectTimeout,
            STREAM_CLIENT_CONNECT,
            \stream_context_create($this->config->streamContext),
        );

        if ($socket === false) {
            throw new RedisConnectionException(\sprintf(
                'Unable to connect to Redis at %s (%d: %s).',
                $this->config->endpoint(),
                $errorCode,
                $errorMessage,
            ));
        }

        return $socket;
    }

    private function authenticate(): void
    {
        if ($this->config->password === null) {
            return;
        }

        $arguments = $this->config->username === null
            ? ['AUTH', $this->config->password]
            : ['AUTH', $this->config->username, $this->config->password];

        if ($this->command($arguments) !== 'OK') {
            throw new RedisProtocolException('Redis returned an invalid AUTH response.');
        }
    }

    private function selectDatabase(): void
    {
        if ($this->config->database === 0) {
            return;
        }

        if ($this->command(['SELECT', (string) $this->config->database]) !== 'OK') {
            throw new RedisProtocolException('Redis returned an invalid SELECT response.');
        }
    }

    private function setClientName(): void
    {
        if ($this->config->clientName === null) {
            return;
        }

        if ($this->command(['CLIENT', 'SETNAME', $this->config->clientName]) !== 'OK') {
            throw new RedisProtocolException('Redis returned an invalid CLIENT SETNAME response.');
        }
    }

    /**
     * @param non-empty-list<string> $arguments
     */
    private function command(array $arguments): mixed
    {
        $this->ensureConnected();
        $this->writeCommand($arguments);

        return $this->readResponse();
    }

    /**
     * @param non-empty-list<string> $arguments
     */
    private function writeCommand(array $arguments): void
    {
        $command = '*' . \count($arguments) . "\r\n";

        foreach ($arguments as $argument) {
            $command .= '$' . \strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        $this->write($command);
    }

    private function write(string $data): void
    {
        $socket  = $this->socket();
        $length  = \strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @\fwrite($socket, \substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                throw new RedisConnectionException('Unable to write to the Redis connection.');
            }

            $written += $bytes;
        }
    }

    private function readResponse(): mixed
    {
        $ownsDeadline = $this->readDeadline === null;

        if ($ownsDeadline) {
            $this->readDeadline = \microtime(true) + $this->config->readTimeout;
        }

        try {
            return $this->readResponseFrame(0);
        } finally {
            if ($ownsDeadline) {
                $this->readDeadline = null;
            }
        }
    }

    private function readResponseFrame(int $depth): mixed
    {
        if ($depth > $this->config->maxResponseDepth) {
            throw new RedisProtocolException('Redis RESP nesting exceeds the configured depth limit.');
        }

        $line = $this->readLine();

        if ($line === '') {
            throw new RedisProtocolException('Redis returned an empty RESP frame.');
        }

        $type    = $line[0];
        $payload = \substr($line, 1);

        return match ($type) {
            '+'     => $payload,
            '-'     => throw new RedisCommandException($payload),
            ':'     => $this->parseInteger($payload),
            '$'     => $this->readBulkString($this->parseInteger($payload)),
            '*'     => $this->readArray($this->parseInteger($payload), $depth),
            default => throw new RedisProtocolException(
                'Redis returned an unknown RESP type byte: ' . \ord($type) . '.',
            ),
        };
    }

    private function readLine(): string
    {
        $socket = $this->socket();
        $this->applyReadDeadline();
        $maximumLineBytes = max(1024, min(536_870_912, $this->config->maxPayloadBytes)) + 4;
        $line             = @\fgets($socket, $maximumLineBytes);

        if ($line === false) {
            $this->throwReadFailure();
        }
        if (! \str_ends_with($line, "\r\n")) {
            throw new RedisProtocolException('Redis returned a RESP line without a CRLF delimiter.');
        }

        return \substr($line, 0, -2);
    }

    private function readBulkString(int $length): ?string
    {
        if ($length === -1) {
            return null;
        }
        if ($length < -1) {
            throw new RedisProtocolException('Redis returned an invalid bulk string length.');
        }
        if ($length > $this->config->maxPayloadBytes) {
            throw new RedisProtocolException('Redis bulk string exceeds the configured payload limit.');
        }

        $value = $this->readBytes($length + 2);

        if (! \str_ends_with($value, "\r\n")) {
            throw new RedisProtocolException('Redis returned a bulk string without a CRLF delimiter.');
        }

        return \substr($value, 0, -2);
    }

    /**
     * @return list<mixed>|null
     */
    private function readArray(int $length, int $depth): ?array
    {
        if ($length === -1) {
            return null;
        }
        if ($length < -1) {
            throw new RedisProtocolException('Redis returned an invalid array length.');
        }
        if ($length > $this->config->maxResponseElements) {
            throw new RedisProtocolException('Redis array exceeds the configured element limit.');
        }

        $values = [];

        for ($index = 0; $index < $length; $index++) {
            $values[] = $this->readResponseFrame($depth + 1);
        }

        return $values;
    }

    private function readBytes(int $length): string
    {
        $socket = $this->socket();
        $value  = '';

        while (\strlen($value) < $length) {
            $this->applyReadDeadline();
            $remaining = $length - \strlen($value);

            if ($remaining < 1) {
                break;
            }

            $chunk = @\fread($socket, $remaining);

            if ($chunk === false || $chunk === '') {
                $this->throwReadFailure();
            }

            $value .= $chunk;
        }

        return $value;
    }

    private function parseInteger(string $value): int
    {
        if ($value === '' || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new RedisProtocolException('Redis returned an invalid RESP integer.');
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if (! \is_int($integer)) {
            throw new RedisProtocolException('Redis RESP integer is outside the supported platform range.');
        }

        return $integer;
    }

    private function readResponseUntil(float $deadline): mixed
    {
        $previousDeadline   = $this->readDeadline;
        $this->readDeadline = $previousDeadline === null
            ? $deadline
            : min($previousDeadline, $deadline);

        try {
            return $this->readResponse();
        } finally {
            $this->readDeadline = $previousDeadline;
        }
    }

    private function applyReadDeadline(): void
    {
        $deadline  = $this->readDeadline ?? (\microtime(true) + $this->config->readTimeout);
        $remaining = $deadline - \microtime(true);

        if ($remaining <= 0.0) {
            throw new RedisConnectionException('Timed out while reading a complete Redis response.');
        }

        $this->setStreamTimeout($remaining);
    }

    private function pingSubscribedConnection(): bool
    {
        $this->writeCommand(['PING']);
        $deadline = \microtime(true) + $this->config->readTimeout;

        while (true) {
            $remaining = $deadline - \microtime(true);

            if ($remaining <= 0.0 || ! $this->waitForReadable($remaining)) {
                return false;
            }

            $response = $this->readResponseUntil($deadline);
            $type     = self::pubSubType($response);

            if ($type === 'pong') {
                if (\count($response) !== 2 || ! \is_string($response[1])) {
                    throw new RedisProtocolException('Redis returned a malformed subscribed PING response.');
                }

                return true;
            }

            $message = $this->toSubscriptionMessage($response);

            if ($message !== null) {
                $this->pendingMessages->enqueue($message);
            }
        }
    }

    private function waitForReadable(float $timeoutSeconds): bool
    {
        $socket   = $this->socket();
        $metadata = \stream_get_meta_data($socket);

        if ($metadata['unread_bytes'] > 0) {
            return true;
        }

        $seconds      = (int) $timeoutSeconds;
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
        $read         = [$socket];
        $write        = [];
        $except       = [];
        $selected     = @\stream_select($read, $write, $except, $seconds, $microseconds);

        if ($selected === false) {
            throw new RedisConnectionException('Unable to wait for data from Redis.');
        }

        return $selected > 0;
    }

    private function setStreamTimeout(float $timeoutSeconds): void
    {
        $socket       = $this->socket();
        $seconds      = (int) $timeoutSeconds;
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
        \stream_set_timeout($socket, $seconds, $microseconds);
    }

    private function throwReadFailure(): never
    {
        $metadata = \is_resource($this->socket) ? \stream_get_meta_data($this->socket) : [];

        if (($metadata['timed_out'] ?? false) === true) {
            throw new RedisConnectionException('Timed out while reading from Redis.');
        }

        throw new RedisConnectionException('Redis closed the connection unexpectedly.');
    }

    private function ensureConnected(): void
    {
        $this->socket();
    }

    /**
     * @return resource
     */
    private function socket(): mixed
    {
        if (! \is_resource($this->socket) || \feof($this->socket)) {
            throw new RedisConnectionException('Redis connection is not open.');
        }

        return $this->socket;
    }

    private function toSubscriptionMessage(mixed $response): ?RedisSubscriptionMessage
    {
        $type = self::pubSubType($response);

        if ($type === 'message') {
            if (\count($response) !== 3 || ! \is_string($response[1]) || ! \is_string($response[2])) {
                throw new RedisProtocolException('Redis returned a malformed Pub/Sub message.');
            }

            return new RedisSubscriptionMessage($response[1], $response[2]);
        }

        if ($type === 'pmessage') {
            if (
                \count($response) !== 4
                || ! \is_string($response[1])
                || ! \is_string($response[2])
                || ! \is_string($response[3])
            ) {
                throw new RedisProtocolException('Redis returned a malformed pattern Pub/Sub message.');
            }

            return new RedisSubscriptionMessage($response[2], $response[3], $response[1]);
        }

        if (
            $type === 'subscribe'
            || $type === 'psubscribe'
            || $type === 'unsubscribe'
            || $type === 'punsubscribe'
            || $type === 'pong'
        ) {
            return null;
        }

        throw new RedisProtocolException('Redis returned an unexpected Pub/Sub frame type: ' . $type . '.');
    }

    /**
     * @param list<mixed> $response
     */
    private function subscriptionAcknowledgementKey(array $response, string $type): string
    {
        if (\count($response) !== 3 || ! \is_string($response[1]) || ! \is_int($response[2])) {
            throw new RedisProtocolException('Redis returned a malformed subscription acknowledgement.');
        }

        return $type . ':' . $response[1];
    }

    private static function pubSubType(mixed $response): string
    {
        if (! \is_array($response) || $response === []) {
            throw new RedisProtocolException('Redis returned a non-array Pub/Sub frame.');
        }

        $type = \array_shift($response);

        if (! \is_string($type)) {
            throw new RedisProtocolException('Redis returned a Pub/Sub frame without a string type.');
        }

        return $type;
    }
}
