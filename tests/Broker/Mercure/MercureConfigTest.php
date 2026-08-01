<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureConfigFactory;
use Maniaba\CodeIgniterSse\Config\Sse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MercureConfigTest extends TestCase
{
    public function testFactoryMapsMercureOptions(): void
    {
        $config                    = new Sse();
        $config->retryMilliseconds = 1250;
        $config->mercure           = [
            'hubUrl'                  => 'http://mercure/.well-known/mercure',
            'publicHubUrl'            => 'https://example.test/.well-known/mercure',
            'topicPrefix'             => 'urn:example:sse:',
            'private'                 => false,
            'authorizeSubscribers'    => false,
            'publisherJwt'            => 'static-publisher-token',
            'publisherKey'            => null,
            'subscriberKey'           => null,
            'publisherAlgorithm'      => 'hs384',
            'subscriberAlgorithm'     => 'hs512',
            'publisherTokenTtl'       => 90,
            'subscriberTokenTtl'      => 600,
            'publisherTopicSelectors' => ['*', 'users.*', 17],
            'connectTimeout'          => 1.5,
            'timeout'                 => 4.5,
            'verifyTls'               => '/etc/ssl/certs/ca.pem',
            'maxPayloadBytes'         => 4096,
            'cookie'                  => [
                'name'     => 'mercureAuth',
                'domain'   => 'example.test',
                'path'     => '/mercure',
                'secure'   => false,
                'httpOnly' => false,
                'sameSite' => 'Lax',
            ],
        ];

        $mercure = (new MercureConfigFactory())->create($config);

        $this->assertSame('http://mercure/.well-known/mercure', $mercure->hubUrl);
        $this->assertSame('https://example.test/.well-known/mercure', $mercure->publicHubUrl);
        $this->assertSame('urn:example:sse:', $mercure->topicPrefix);
        $this->assertFalse($mercure->privateUpdates);
        $this->assertFalse($mercure->authorizeSubscribers);
        $this->assertSame('static-publisher-token', $mercure->publisherJwt);
        $this->assertNull($mercure->publisherKey);
        $this->assertNull($mercure->subscriberKey);
        $this->assertSame('HS384', $mercure->publisherAlgorithm);
        $this->assertSame('HS512', $mercure->subscriberAlgorithm);
        $this->assertSame(90, $mercure->publisherTokenTtl);
        $this->assertSame(600, $mercure->subscriberTokenTtl);
        $this->assertSame(['*', 'users.*'], $mercure->publisherTopicSelectors);
        $this->assertSame(1.5, $mercure->connectTimeout);
        $this->assertSame(4.5, $mercure->timeout);
        $this->assertSame('/etc/ssl/certs/ca.pem', $mercure->verifyTls);
        $this->assertSame(4096, $mercure->maxPayloadBytes);
        $this->assertSame(1250, $mercure->retryMilliseconds);
        $this->assertSame('mercureAuth', $mercure->cookieName);
        $this->assertSame('example.test', $mercure->cookieDomain);
        $this->assertSame('/mercure', $mercure->cookiePath);
        $this->assertFalse($mercure->cookieSecure);
        $this->assertFalse($mercure->cookieHttpOnly);
        $this->assertSame('Lax', $mercure->cookieSameSite);
    }

    #[DataProvider('provideRejectsInvalidMercureConfig')]
    public function testRejectsInvalidMercureConfig(callable $configure): void
    {
        $config = new Sse();
        $configure($config);

        $this->expectException(MercureConfigurationException::class);

        (new MercureConfigFactory())->create($config);
    }

    /**
     * @return iterable<string, array{callable(Sse): void}>
     */
    public static function provideRejectsInvalidMercureConfig(): iterable
    {
        yield 'missing publisher credentials' => [
            static function (Sse $config): void {
                $config->mercure = [
                    'subscriberKey' => 'subscriber-test-secret-at-least-32-bytes',
                ];
            },
        ];

        yield 'missing subscriber key' => [
            static function (Sse $config): void {
                $config->mercure = [
                    'publisherKey' => 'publisher-test-secret-at-least-32-bytes',
                ];
            },
        ];

        yield 'private updates without subscriber authorization' => [
            static function (Sse $config): void {
                $config->mercure = [
                    'private'              => true,
                    'authorizeSubscribers' => false,
                    'publisherKey'         => 'publisher-test-secret-at-least-32-bytes',
                ];
            },
        ];

        yield 'publisher selectors must be a list' => [
            static function (Sse $config): void {
                $config->mercure = [
                    'publisherKey'            => 'publisher-test-secret-at-least-32-bytes',
                    'subscriberKey'           => 'subscriber-test-secret-at-least-32-bytes',
                    'publisherTopicSelectors' => '*',
                ];
            },
        ];

        foreach (['{', '}', '*', '?', '[', ']'] as $character) {
            yield sprintf('topic prefix rejects "%s"', $character) => [
                static function (Sse $config) use ($character): void {
                    $config->mercure = [
                        'topicPrefix'   => 'https://example.test/sse/' . $character . 'topic',
                        'publisherKey'  => 'publisher-test-secret-at-least-32-bytes',
                        'subscriberKey' => 'subscriber-test-secret-at-least-32-bytes',
                    ];
                },
            ];
        }
    }
}
