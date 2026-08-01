<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Endpoint\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\HTTP\LegacySseResponse;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Support\Tests\FixedEventIdGenerator;
use Support\Tests\RecordingSubscriber;

/**
 * @internal
 */
final class SubscriptionEndpointTest extends CIUnitTestCase
{
    public function testLocalEndpointRejectsMissingEventStreamAcceptHeader(): void
    {
        [$request, $response] = $this->http();
        $endpoint             = new LocalSseSubscriptionEndpoint($this->manager());

        $result = $endpoint->preflight($request, $response);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(406, $result->getStatusCode());
        $this->assertStringContainsString('not_acceptable', (string) $result->getBody());
    }

    #[DataProvider('provideLocalEndpointAcceptsEventStreamCompatibleRequests')]
    public function testLocalEndpointAcceptsEventStreamCompatibleRequests(
        ?string $accept,
        bool $requireAcceptHeader,
    ): void {
        [$request, $response] = $this->http($accept);
        $endpoint             = new LocalSseSubscriptionEndpoint(
            $this->manager(),
            $requireAcceptHeader,
        );

        $this->assertNull($endpoint->preflight($request, $response));
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function provideLocalEndpointAcceptsEventStreamCompatibleRequests(): iterable
    {
        yield 'event stream' => ['text/event-stream', true];

        yield 'event stream with parameters' => ['text/event-stream; charset=utf-8', true];

        yield 'text wildcard' => ['text/*;q=0.5', true];

        yield 'wildcard' => ['*/*', true];

        yield 'accept header disabled' => [null, false];
    }

    public function testLocalEndpointReturnsBrowserBootstrapForJsonRequests(): void
    {
        [$request, $response] = $this->http('application/json');
        $endpoint             = new LocalSseSubscriptionEndpoint($this->manager());

        $this->assertNull($endpoint->preflight($request, $response));

        $result = $endpoint->respond($request, $response, ['public.news']);
        $body   = $result->getBody();

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringStartsWith('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertSame('Accept', $result->getHeaderLine('Vary'));
        $this->assertSame('nosniff', $result->getHeaderLine('X-Content-Type-Options'));
        $this->assertIsString($body);
        $this->assertSame(
            ['url' => null, 'expiresAt' => null],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[DataProvider('provideLocalEndpointHonorsPreferredRepresentation')]
    public function testLocalEndpointHonorsPreferredRepresentation(
        string $accept,
        bool $expectsBootstrap,
    ): void {
        [$request, $response] = $this->http($accept);
        $endpoint             = new LocalSseSubscriptionEndpoint($this->manager());

        $this->assertNull($endpoint->preflight($request, $response));

        $result = $endpoint->respond($request, $response, ['public.news']);

        if ($expectsBootstrap) {
            $this->assertStringStartsWith('application/json', $result->getHeaderLine('Content-Type'));

            return;
        }

        $this->assertInstanceOf(LegacySseResponse::class, $result);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideLocalEndpointHonorsPreferredRepresentation(): iterable
    {
        yield 'stream has higher quality' => [
            'application/json;q=0.1, text/event-stream;q=1',
            false,
        ];

        yield 'bootstrap has higher quality' => [
            'application/json;q=1, text/event-stream;q=0.1',
            true,
        ];

        yield 'stream wins equal quality by order' => [
            'text/event-stream, application/json',
            false,
        ];

        yield 'bootstrap wins equal quality by order' => [
            'application/json, text/event-stream',
            true,
        ];
    }

    #[DataProvider('provideLocalEndpointRejectsUnacceptableEventStreamRequests')]
    public function testLocalEndpointRejectsUnacceptableEventStreamRequests(string $accept): void
    {
        [$request, $response] = $this->http($accept);
        $endpoint             = new LocalSseSubscriptionEndpoint($this->manager());

        $result = $endpoint->preflight($request, $response);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(406, $result->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalEndpointRejectsUnacceptableEventStreamRequests(): iterable
    {
        yield 'event stream q zero' => ['text/event-stream;q=0'];

        yield 'wildcard q zero' => ['*/*;q=0'];

        yield 'bootstrap q zero' => ['application/json;q=0'];

        yield 'specific q zero wins over wildcard' => ['text/event-stream;q=0, */*;q=1'];

        yield 'text wildcard q zero wins over wildcard' => ['text/*;q=0, */*;q=1'];
    }

    public function testLocalEndpointCreatesStreamingResponse(): void
    {
        [$request, $response] = $this->http('text/event-stream');
        $endpoint             = new LocalSseSubscriptionEndpoint($this->manager());

        $result = $endpoint->respond($request, $response, ['public.news']);

        $this->assertInstanceOf(LegacySseResponse::class, $result);
        $this->assertSame('Accept', $result->getHeaderLine('Vary'));
        $this->assertSame('nosniff', $result->getHeaderLine('X-Content-Type-Options'));

        $result->pretend();
        ob_start();
        $result->send();
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString("retry: 3000\n\n", $output);
        $this->assertStringContainsString("event: sse.connected\n", $output);
        $this->assertStringContainsString("id: connected-id\n", $output);
        $this->assertStringContainsString('"channels":["public.news"]', $output);
    }

    public function testLocalEndpointExposesConfiguredChannelSelectorValidator(): void
    {
        $validator = new class () implements ChannelSelectorValidatorInterface {
            public function assertValid(string $selector): void
            {
            }
        };
        $endpoint = new LocalSseSubscriptionEndpoint(
            $this->manager(),
            channelSelectorValidator: $validator,
        );

        $this->assertSame($validator, $endpoint->channelSelectorValidator());
    }

    public function testMercureEndpointReturnsBootstrapPayloadAndCookie(): void
    {
        [$request, $response] = $this->http();
        $endpoint             = new MercureSubscriptionEndpoint($this->mercureConfig());

        $result = $endpoint->respond($request, $response, ['public.news']);
        $body   = $result->getBody();

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('private', $result->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertSame(
            '<https://example.test/.well-known/mercure>; rel="mercure"',
            $result->getHeaderLine('Link'),
        );
        $this->assertSame('nosniff', $result->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('Accept', $result->getHeaderLine('Vary'));
        $this->assertIsString($body);

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://example.test/.well-known/mercure', $decoded['url']);
        $this->assertSame(
            ['topic' => ['urn:example:sse:public.news']],
            $decoded['query'],
        );
        $this->assertIsInt($decoded['expiresAt']);

        $cookie = $result->getCookie('mercureAuthorization');
        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHTTPOnly());
    }

    public function testMercureEndpointDeletesCookieWhenSubscriberAuthorizationIsDisabled(): void
    {
        [$request, $response] = $this->http();
        $config               = $this->mercureConfig([
            'private'              => false,
            'authorizeSubscribers' => false,
            'subscriberKey'        => null,
        ]);
        $endpoint = new MercureSubscriptionEndpoint($config);

        $result = $endpoint->respond($request, $response, ['public.news']);
        $body   = $result->getBody();

        $this->assertIsString($body);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($decoded['expiresAt']);
        $cookie = $result->getCookie('mercureAuthorization');
        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('', $cookie->getValue());
    }

    public function testMercureEndpointUsesPlainChannelNameSelectors(): void
    {
        $endpoint = new MercureSubscriptionEndpoint($this->mercureConfig());

        $this->assertInstanceOf(ChannelNameValidator::class, $endpoint->channelSelectorValidator());
    }

    /**
     * @return array{RequestInterface, ResponseInterface}
     */
    private function http(?string $accept = null): array
    {
        $request  = single_service('request');
        $response = single_service('response');

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);

        $request->removeHeader('Accept');

        if ($accept !== null) {
            $request->setHeader('Accept', $accept);
        }

        return [$request, $response];
    }

    private function manager(): SseConnectionManager
    {
        return new SseConnectionManager(
            new RecordingSubscriber(),
            new EventFactory(new FixedEventIdGenerator('connected-id')),
        );
    }

    /**
     * @param array<string, mixed> $mercure
     */
    private function mercureConfig(array $mercure = []): Sse
    {
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = array_replace_recursive([
            'hubUrl'        => 'http://mercure/.well-known/mercure',
            'publicHubUrl'  => 'https://example.test/.well-known/mercure',
            'topicPrefix'   => 'urn:example:sse:',
            'publisherKey'  => 'publisher-test-secret',
            'subscriberKey' => 'subscriber-test-secret',
            'cookie'        => [
                'name'     => 'mercureAuthorization',
                'secure'   => true,
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ],
        ], $mercure);

        return $config;
    }
}
