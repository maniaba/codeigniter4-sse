<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercurePublishException;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureConfigFactory;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureHttpClientInterface;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureHttpResponse;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MercurePublisherTest extends TestCase
{
    public function testPublishesPackageEnvelopeToMappedPrivateTopic(): void
    {
        $http = new class () implements MercureHttpClientInterface {
            /**
             * @var array<string, mixed>
             */
            public array $request = [];

            public function post(
                string $url,
                array $headers,
                array $form,
                float $connectTimeout,
                float $timeout,
                bool|string $verifyTls,
            ): MercureHttpResponse {
                $this->request = compact(
                    'url',
                    'headers',
                    'form',
                    'connectTimeout',
                    'timeout',
                    'verifyTls',
                );

                return new MercureHttpResponse(200, 'urn:uuid:update-id');
            }
        };
        $config    = $this->config();
        $publisher = new MercurePublisher(
            (new MercureConfigFactory())->create($config),
            new JsonEventSerializer(),
            $http,
        );

        $publisher->publish(
            'users.42',
            new SseEvent('profile.updated', ['name' => 'Ada'], 'event-1'),
        );

        $this->assertSame(
            'http://mercure/.well-known/mercure',
            $http->request['url'],
        );
        $this->assertSame(
            'urn:example:sse:users.42',
            $http->request['form']['topic'],
        );
        $this->assertSame('profile.updated', $http->request['form']['type']);
        $this->assertSame('event-1', $http->request['form']['id']);
        $this->assertSame('on', $http->request['form']['private']);
        $this->assertSame(3000, $http->request['form']['retry']);
        $this->assertStringStartsWith(
            'Bearer ',
            $http->request['headers']['Authorization'],
        );

        $payload = json_decode(
            $http->request['form']['data'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('users.42', $payload['channel']);
        $this->assertSame('profile.updated', $payload['event']);
        $this->assertSame(['name' => 'Ada'], $payload['data']);
    }

    public function testRejectedUpdateIncludesHubStatusAndSafeResponseDetails(): void
    {
        $http = new class () implements MercureHttpClientInterface {
            public function post(
                string $url,
                array $headers,
                array $form,
                float $connectTimeout,
                float $timeout,
                bool|string $verifyTls,
            ): MercureHttpResponse {
                return new MercureHttpResponse(403, "insufficient topic scope\n");
            }
        };
        $publisher = new MercurePublisher(
            (new MercureConfigFactory())->create($this->config()),
            new JsonEventSerializer(),
            $http,
        );

        $this->expectException(MercurePublishException::class);
        $this->expectExceptionMessage(
            'The Mercure Hub rejected the SSE event with HTTP 403: insufficient topic scope',
        );

        $publisher->publish(
            'users.42',
            new SseEvent('profile.updated', [], 'event-1'),
        );
    }

    public function testMercureReservedEventIdIsRejectedBeforeHttpRequest(): void
    {
        $http = new class () implements MercureHttpClientInterface {
            public int $calls = 0;

            public function post(
                string $url,
                array $headers,
                array $form,
                float $connectTimeout,
                float $timeout,
                bool|string $verifyTls,
            ): MercureHttpResponse {
                $this->calls++;

                return new MercureHttpResponse(200, 'unexpected');
            }
        };
        $publisher = new MercurePublisher(
            (new MercureConfigFactory())->create($this->config()),
            new JsonEventSerializer(),
            $http,
        );

        try {
            $publisher->publish(
                'users.42',
                new SseEvent('profile.updated', [], '#reserved'),
            );
            $this->fail('Expected the Mercure event ID to be rejected.');
        } catch (MercurePublishException $exception) {
            $this->assertStringContainsString('must not start with #', $exception->getMessage());
        }

        $this->assertSame(0, $http->calls);
    }

    private function config(): Sse
    {
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = [
            'hubUrl'        => 'http://mercure/.well-known/mercure',
            'publicHubUrl'  => 'https://example.test/.well-known/mercure',
            'topicPrefix'   => 'urn:example:sse:',
            'publisherKey'  => 'publisher-test-secret',
            'subscriberKey' => 'subscriber-test-secret',
            'cookie'        => [
                'secure' => true,
            ],
        ];

        return $config;
    }
}
