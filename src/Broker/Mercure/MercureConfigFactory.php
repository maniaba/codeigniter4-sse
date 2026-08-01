<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Config\AbstractBrokerConfigFactory;
use Maniaba\CodeIgniterSse\Config\Sse;

final class MercureConfigFactory extends AbstractBrokerConfigFactory
{
    public function create(Sse $config): MercureConfig
    {
        $mercure = $config->mercure();
        $cookie  = self::arrayOption($mercure['cookie'] ?? null);

        return new MercureConfig(
            hubUrl: (string) $mercure['hubUrl'],
            publicHubUrl: (string) $mercure['publicHubUrl'],
            topicPrefix: (string) $mercure['topicPrefix'],
            privateUpdates: (bool) $mercure['private'],
            authorizeSubscribers: (bool) $mercure['authorizeSubscribers'],
            publisherJwt: self::nullableString($mercure['publisherJwt'] ?? null),
            publisherKey: self::nullableString($mercure['publisherKey'] ?? null),
            subscriberKey: self::nullableString($mercure['subscriberKey'] ?? null),
            publisherAlgorithm: strtoupper((string) $mercure['publisherAlgorithm']),
            subscriberAlgorithm: strtoupper((string) $mercure['subscriberAlgorithm']),
            publisherTokenTtl: (int) $mercure['publisherTokenTtl'],
            subscriberTokenTtl: (int) $mercure['subscriberTokenTtl'],
            publisherTopicSelectors: self::stringList($mercure['publisherTopicSelectors'] ?? null),
            connectTimeout: (float) $mercure['connectTimeout'],
            timeout: (float) $mercure['timeout'],
            verifyTls: is_string($mercure['verifyTls'])
                ? $mercure['verifyTls']
                : (bool) $mercure['verifyTls'],
            maxPayloadBytes: (int) $mercure['maxPayloadBytes'],
            retryMilliseconds: $config->retryMilliseconds,
            cookieName: (string) ($cookie['name'] ?? ''),
            cookieDomain: (string) ($cookie['domain'] ?? ''),
            cookiePath: (string) ($cookie['path'] ?? ''),
            cookieSecure: (bool) ($cookie['secure'] ?? true),
            cookieHttpOnly: (bool) ($cookie['httpOnly'] ?? true),
            cookieSameSite: (string) ($cookie['sameSite'] ?? 'Lax'),
        );
    }
}
