<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\HTTP\LegacySseResponse;
use Maniaba\CodeIgniterSse\HTTP\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\HTTP\MercureSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FixedEventIdGenerator;
use Tests\Support\RecordingSubscriber;

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

        yield 'wildcard' => ['application/json, */*', true];

        yield 'accept header disabled' => [null, false];
    }

    public function testLocalEndpointCreatesStreamingResponse(): void
    {
        [$request, $response] = $this->http('text/event-stream');
        $endpoint             = new LocalSseSubscriptionEndpoint($this->manager());

        $result = $endpoint->respond($request, $response, ['public.news']);

        $this->assertInstanceOf(LegacySseResponse::class, $result);
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
        $this->assertIsString($body);

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('mercure', $decoded['transport']);
        $this->assertSame('https://example.test/.well-known/mercure', $decoded['hub']);
        $this->assertSame(['urn:example:sse:public.news'], $decoded['topics']);
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
            new JsonEventSerializer(),
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
