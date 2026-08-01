<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Config\Sse;

final class MercureConfigFactory
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
            publisherTopicSelectors: self::publisherTopicSelectors($mercure),
            allowGlobalPublisherSelector: (bool) $mercure['allowGlobalPublisherSelector'],
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

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $mercure
     *
     * @return list<string>
     */
    private static function publisherTopicSelectors(array $mercure): array
    {
        if (($mercure['publisherTopicSelectors'] ?? null) === null) {
            return [(string) $mercure['topicPrefix'] . '{channel}'];
        }

        return self::stringList($mercure['publisherTopicSelectors']);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            is_string(...),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayOption(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
