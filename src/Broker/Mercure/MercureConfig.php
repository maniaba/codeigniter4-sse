<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;

final readonly class MercureConfig
{
    private const ALGORITHMS = ['HS256', 'HS384', 'HS512'];

    /**
     * @param list<string> $publisherTopicSelectors
     */
    public function __construct(
        public string $hubUrl,
        public string $publicHubUrl,
        public string $topicPrefix,
        public bool $privateUpdates,
        public bool $authorizeSubscribers,
        public ?string $publisherJwt,
        public ?string $publisherKey,
        public ?string $subscriberKey,
        public string $publisherAlgorithm,
        public string $subscriberAlgorithm,
        public int $publisherTokenTtl,
        public int $subscriberTokenTtl,
        public array $publisherTopicSelectors,
        public bool $allowGlobalPublisherSelector,
        public float $connectTimeout,
        public float $timeout,
        public bool|string $verifyTls,
        public int $maxPayloadBytes,
        public int $retryMilliseconds,
        public string $cookieName,
        public string $cookieDomain,
        public string $cookiePath,
        public bool $cookieSecure,
        public bool $cookieHttpOnly,
        public string $cookieSameSite,
    ) {
        $this->assertUrl($this->hubUrl, 'server-side Hub');
        $this->assertUrl($this->publicHubUrl, 'public Hub');
        $this->assertTopicPrefix($this->topicPrefix);

        if ($this->publisherJwt === null && $this->publisherKey === null) {
            throw new MercureConfigurationException(
                'Mercure requires either publisherJwt or publisherKey.',
            );
        }

        if ($this->authorizeSubscribers && $this->subscriberKey === null) {
            throw new MercureConfigurationException(
                'Mercure subscriberKey is required when subscriber authorization is enabled.',
            );
        }

        if ($this->privateUpdates && ! $this->authorizeSubscribers) {
            throw new MercureConfigurationException(
                'Private Mercure updates require subscriber authorization.',
            );
        }

        $this->assertAlgorithm($this->publisherAlgorithm, 'publisher');
        $this->assertAlgorithm($this->subscriberAlgorithm, 'subscriber');

        if ($this->publisherTokenTtl < 30) {
            throw new MercureConfigurationException(
                'Mercure publisherTokenTtl must be at least 30 seconds.',
            );
        }

        if ($this->subscriberTokenTtl < 60) {
            throw new MercureConfigurationException(
                'Mercure subscriberTokenTtl must be at least 60 seconds.',
            );
        }

        if ($this->publisherTopicSelectors === []) {
            throw new MercureConfigurationException(
                'Mercure publisherTopicSelectors must contain at least one selector.',
            );
        }

        if (
            in_array('*', $this->publisherTopicSelectors, true)
            && ! $this->allowGlobalPublisherSelector
        ) {
            throw new MercureConfigurationException(
                'The global Mercure publisher selector "*" is disabled. '
                . 'Enable it explicitly only when required.',
            );
        }

        foreach ($this->publisherTopicSelectors as $selector) {
            if ($selector === '' || strpbrk($selector, "\r\n\0") !== false) {
                throw new MercureConfigurationException(
                    'Mercure publisher topic selectors must be non-empty single-line strings.',
                );
            }
        }

        if ($this->connectTimeout <= 0.0 || $this->timeout <= 0.0) {
            throw new MercureConfigurationException(
                'Mercure HTTP timeouts must be greater than zero.',
            );
        }

        if ($this->maxPayloadBytes < 1024 || $this->maxPayloadBytes > 536_870_912) {
            throw new MercureConfigurationException(
                'Mercure maximum payload size must be between 1024 bytes and 512 MiB.',
            );
        }

        if ($this->retryMilliseconds < 0) {
            throw new MercureConfigurationException(
                'Mercure retryMilliseconds must not be negative.',
            );
        }

        if (
            $this->cookieName === ''
            || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $this->cookieName) !== 1
        ) {
            throw new MercureConfigurationException('Mercure cookie name is invalid.');
        }

        if ($this->cookiePath === '' || strpbrk($this->cookiePath, "\r\n\0") !== false) {
            throw new MercureConfigurationException('Mercure cookie path is invalid.');
        }

        if (! in_array(strtolower($this->cookieSameSite), ['lax', 'strict', 'none'], true)) {
            throw new MercureConfigurationException(
                'Mercure cookie sameSite must be Lax, Strict or None.',
            );
        }

        if (strtolower($this->cookieSameSite) === 'none' && ! $this->cookieSecure) {
            throw new MercureConfigurationException(
                'Mercure SameSite=None cookies must be secure.',
            );
        }
    }

    private function assertTopicPrefix(string $prefix): void
    {
        if (
            $prefix === ''
            || strpbrk($prefix, "\r\n\0{}*?[]") !== false
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $prefix) !== 1
        ) {
            throw new MercureConfigurationException(
                'Mercure topicPrefix must be a literal absolute IRI prefix '
                . 'without wildcard or URI-template characters.',
            );
        }
    }

    public function publisherToken(MercureJwtFactory $tokens, ?int $issuedAt = null): string
    {
        if ($this->publisherJwt !== null) {
            return $this->publisherJwt;
        }

        return $tokens->create(
            ['publish' => $this->publisherTopicSelectors],
            $this->publisherKey ?? '',
            $this->publisherAlgorithm,
            $this->publisherTokenTtl,
            $issuedAt,
        );
    }

    private function assertUrl(string $url, string $label): void
    {
        $parts = parse_url($url);

        if (
            $parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || strpbrk($url, "\r\n\0") !== false
        ) {
            throw new MercureConfigurationException(
                sprintf('The Mercure %s URL must be an absolute HTTP(S) URL without credentials.', $label),
            );
        }
    }

    private function assertAlgorithm(string $algorithm, string $token): void
    {
        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new MercureConfigurationException(
                sprintf(
                    'Mercure %s algorithm must be one of %s.',
                    $token,
                    implode(', ', self::ALGORITHMS),
                ),
            );
        }
    }
}
