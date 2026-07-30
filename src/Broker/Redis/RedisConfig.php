<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConfigurationException;

final readonly class RedisConfig
{
    public ?string $password;
    public ?string $username;

    /**
     * @param array<string, array<string, mixed>> $streamContext
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        ?string $password = null,
        public int $database = 0,
        public float $connectTimeout = 2.0,
        public float $readTimeout = 2.0,
        public string $channelPrefix = 'app:sse:',
        public float $pollIntervalSeconds = 1.0,
        public float $subscriberPingIntervalSeconds = 15.0,
        public int $maxReconnectAttempts = 3,
        public int $reconnectDelayMilliseconds = 100,
        public int $deduplicationCapacity = 1024,
        public int $maxPayloadBytes = 1_048_576,
        public int $maxResponseElements = 1024,
        public int $maxResponseDepth = 8,
        public bool $allowPatternSubscriptions = false,
        ?string $username = null,
        public string $scheme = 'tcp',
        public array $streamContext = [],
        public ?string $clientName = null,
    ) {
        $this->password = self::nullableString($password);
        $this->username = self::nullableString($username);

        if (\trim($this->host) === '') {
            throw new RedisConfigurationException('Redis host must not be empty.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new RedisConfigurationException('Redis port must be between 1 and 65535.');
        }
        if ($this->scheme !== 'tcp' && $this->scheme !== 'tls') {
            throw new RedisConfigurationException('Redis scheme must be either "tcp" or "tls".');
        }
        if ($this->database < 0) {
            throw new RedisConfigurationException('Redis database must not be negative.');
        }
        if ($this->connectTimeout <= 0.0) {
            throw new RedisConfigurationException('Redis connect timeout must be greater than zero.');
        }
        if ($this->readTimeout <= 0.0) {
            throw new RedisConfigurationException('Redis read timeout must be greater than zero.');
        }
        if ($this->pollIntervalSeconds <= 0.0) {
            throw new RedisConfigurationException('Redis poll interval must be greater than zero.');
        }
        if ($this->subscriberPingIntervalSeconds <= 0.0) {
            throw new RedisConfigurationException('Redis subscriber PING interval must be greater than zero.');
        }
        if ($this->maxReconnectAttempts < 0) {
            throw new RedisConfigurationException('Redis reconnect attempts must not be negative.');
        }
        if ($this->reconnectDelayMilliseconds < 0) {
            throw new RedisConfigurationException('Redis reconnect delay must not be negative.');
        }
        if ($this->deduplicationCapacity < 1) {
            throw new RedisConfigurationException('Redis deduplication capacity must be at least one.');
        }
        if ($this->maxPayloadBytes < 1024 || $this->maxPayloadBytes > 536_870_912) {
            throw new RedisConfigurationException(
                'Redis maximum payload size must be between 1024 bytes and 512 MiB.',
            );
        }
        if ($this->maxResponseElements < 1 || $this->maxResponseElements > 65_536) {
            throw new RedisConfigurationException(
                'Redis maximum response elements must be between 1 and 65536.',
            );
        }
        if ($this->maxResponseDepth < 1 || $this->maxResponseDepth > 64) {
            throw new RedisConfigurationException(
                'Redis maximum response depth must be between 1 and 64.',
            );
        }
        if ($this->username !== null && $this->password === null) {
            throw new RedisConfigurationException('A Redis password is required when a username is configured.');
        }
        if (
            \str_contains($this->channelPrefix, "\r")
            || \str_contains($this->channelPrefix, "\n")
            || \str_contains($this->channelPrefix, "\0")
        ) {
            throw new RedisConfigurationException('Redis channel prefix contains an invalid control character.');
        }
        if (
            $this->allowPatternSubscriptions
            && \preg_match('/[*?\\[\\]\\\\\\\\]/', $this->channelPrefix) === 1
        ) {
            throw new RedisConfigurationException(
                'Redis channel prefix must not contain glob metacharacters when patterns are enabled.',
            );
        }
        if (
            $this->clientName !== null
            && (
                $this->clientName === ''
                || \str_contains($this->clientName, "\r")
                || \str_contains($this->clientName, "\n")
                || \str_contains($this->clientName, "\0")
            )
        ) {
            throw new RedisConfigurationException(
                'Redis client name is empty or contains an invalid control character.',
            );
        }
    }

    public function endpoint(): string
    {
        $host = $this->host;

        if (\str_contains($host, ':') && \preg_match('/^\[.*]$/D', $host) !== 1) {
            $host = '[' . $host . ']';
        }

        return $this->scheme . '://' . $host . ':' . $this->port;
    }

    private static function nullableString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
