<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\AbstractBrokerConfigFactory;
use Maniaba\CodeIgniterSse\Config\Sse;

final class RedisConfigFactory extends AbstractBrokerConfigFactory
{
    public function create(Sse $config): RedisConfig
    {
        $redis = $config->redis();

        return new RedisConfig(
            host: (string) $redis['host'],
            port: (int) $redis['port'],
            password: self::nullableString($redis['password'] ?? null),
            database: (int) $redis['database'],
            connectTimeout: (float) $redis['connectTimeout'],
            readTimeout: (float) $redis['readTimeout'],
            channelPrefix: $config->channelPrefix,
            pollIntervalSeconds: (float) $redis['pollInterval'],
            subscriberPingIntervalSeconds: (float) $redis['pingInterval'],
            maxReconnectAttempts: (int) $redis['reconnectAttempts'],
            reconnectDelayMilliseconds: (int) $redis['reconnectDelayMilliseconds'],
            deduplicationCapacity: (int) $redis['deduplicationCapacity'],
            maxPayloadBytes: (int) $redis['maxPayloadBytes'],
            maxResponseElements: (int) $redis['maxResponseElements'],
            maxResponseDepth: (int) $redis['maxResponseDepth'],
            allowPatternSubscriptions: (bool) $redis['allowPatternSubscriptions'],
            username: self::nullableString($redis['username'] ?? null),
            scheme: (string) $redis['scheme'],
            streamContext: self::arrayOption($redis['streamContext'] ?? null),
            clientName: self::nullableString($redis['clientName'] ?? null),
        );
    }
}
