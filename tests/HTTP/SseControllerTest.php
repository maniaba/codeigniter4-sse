<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Superglobals;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services as FrameworkServices;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Endpoint\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\HTTP\SseController;
use Maniaba\CodeIgniterSse\HTTP\SseResponseFactory;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Psr\Log\LoggerInterface;
use Support\Tests\Adapter\BasicBrokerAdapter;
use Support\Tests\FixedEventIdGenerator;
use Support\Tests\RecordingSubscriber;

/**
 * @internal
 */
final class SseControllerTest extends CIUnitTestCase
{
    public function testRejectsUnsupportedAcceptHeader(): void
    {
        $result = $this->controllerResponse(null, 'text/html');
        $body   = $result->getBody();

        $this->assertSame(406, $result->getStatusCode());
        $this->assertIsString($body);
        $this->assertStringContainsString('not_acceptable', $body);
    }

    public function testLocalRouteRejectsJsonAcceptHeader(): void
    {
        $manager = new SseConnectionManager(
            new RecordingSubscriber(),
            new EventFactory(new FixedEventIdGenerator('connected-id')),
        );
        $superglobals = service('superglobals');
        $this->assertInstanceOf(Superglobals::class, $superglobals);
        $previousGet = $superglobals->getGetArray();
        $superglobals->setGetArray(['channels' => 'public.news']);

        try {
            $result = $this->controllerResponse(
                null,
                'application/json',
                new BasicBrokerAdapter(
                    endpoint: new LocalSseSubscriptionEndpoint($manager),
                ),
            );
            $body = $result->getBody();

            $this->assertSame(406, $result->getStatusCode());
            $this->assertStringStartsWith('application/json', $result->getHeaderLine('Content-Type'));
            $this->assertSame('Accept', $result->getHeaderLine('Vary'));
            $this->assertIsString($body);
            $this->assertStringContainsString('not_acceptable', $body);
        } finally {
            $superglobals->setGetArray($previousGet);
        }
    }

    public function testRejectsUnknownOriginBeforeContentNegotiation(): void
    {
        $result = $this->controllerResponse('https://attacker.example.com', null);
        $body   = $result->getBody();

        $this->assertSame(403, $result->getStatusCode());
        $this->assertIsString($body);
        $this->assertStringContainsString('origin_forbidden', $body);
    }

    public function testAuthorizedPublicChannelProducesAStreamingResponse(): void
    {
        $manager = new SseConnectionManager(
            new RecordingSubscriber(),
            new EventFactory(new FixedEventIdGenerator('connected-id')),
        );
        $factoryResponse = single_service('response');
        $this->assertInstanceOf(ResponseInterface::class, $factoryResponse);

        $superglobals = service('superglobals');
        $this->assertInstanceOf(Superglobals::class, $superglobals);
        $previousGet = $superglobals->getGetArray();
        $superglobals->setGetArray(['channels' => 'public.news']);

        try {
            $result = $this->controllerResponse(
                null,
                'text/event-stream',
                new BasicBrokerAdapter(
                    endpoint: new LocalSseSubscriptionEndpoint(
                        $manager,
                        responseFactory: new SseResponseFactory($factoryResponse),
                    ),
                ),
            );

            ob_start();
            $result->send();
            $output = ob_get_clean();

            $this->assertIsString($output);
            $this->assertSame(
                'text/event-stream; charset=UTF-8',
                $result->getHeaderLine('Content-Type'),
            );
            $this->assertSame('nosniff', $result->getHeaderLine('X-Content-Type-Options'));
            $this->assertStringContainsString("retry: 3000\n\n", $output);
            $this->assertStringContainsString("event: sse.connected\n", $output);
            $this->assertStringContainsString("id: connected-id\n", $output);
            $this->assertStringContainsString('"channels":["public.news"]', $output);
        } finally {
            $superglobals->setGetArray($previousGet);
        }
    }

    public function testMercureRouteAuthorizesChannelsWithoutOpeningAPhpStream(): void
    {
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = [
            'hubUrl'        => 'http://mercure/.well-known/mercure',
            'publicHubUrl'  => 'https://example.test/.well-known/mercure',
            'topicPrefix'   => 'urn:example:sse:',
            'publisherKey'  => 'publisher-test-secret-at-least-32-bytes',
            'subscriberKey' => 'subscriber-test-secret-at-least-32-bytes',
            'cookie'        => [
                'name'     => 'mercureAuthorization',
                'secure'   => true,
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ],
        ];
        $request  = single_service('request');
        $response = single_service('response');
        $logger   = service('logger');

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $superglobals = service('superglobals');
        $this->assertInstanceOf(Superglobals::class, $superglobals);
        $previousGet = $superglobals->getGetArray();
        $superglobals->setGetArray(['channels' => 'public.news,public.status']);
        $request->removeHeader('Origin');
        $request->removeHeader('Accept');
        $request->removeHeader('Sec-Fetch-Site');

        try {
            FrameworkServices::injectMock(
                'sseBrokerAdapter',
                new BasicBrokerAdapter(endpoint: new MercureSubscriptionEndpoint($config)),
            );

            $controller = new SseController();
            $controller->initController($request, $response, $logger);
            $result = $controller->stream();
            $body   = $result->getBody();

            $this->assertSame(200, $result->getStatusCode());
            $this->assertStringStartsWith('application/json', $result->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('private', $result->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
            $this->assertSame(
                '<https://example.test/.well-known/mercure>; rel="mercure"',
                $result->getHeaderLine('Link'),
            );
            $this->assertIsString($body);
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(
                'https://example.test/.well-known/mercure',
                $decoded['hub'],
            );
            $this->assertSame(
                [
                    'urn:example:sse:public.news',
                    'urn:example:sse:public.status',
                ],
                $decoded['topics'],
            );
            $this->assertIsInt($decoded['expiresAt']);

            $cookie = $result->getCookie('mercureAuthorization');
            $this->assertInstanceOf(Cookie::class, $cookie);
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHTTPOnly());
            $this->assertSame('Lax', $cookie->getSameSite());
        } finally {
            $superglobals->setGetArray($previousGet);
            FrameworkServices::resetSingle('sseBrokerAdapter');
        }
    }

    public function testMercureRouteRejectsCrossSiteBootstrapRequest(): void
    {
        $result = $this->mercureBootstrapResponse('cross-site');
        $body   = $result->getBody();

        $this->assertSame(403, $result->getStatusCode());
        $this->assertIsString($body);
        $this->assertStringContainsString('origin_forbidden', $body);
        $this->assertStringContainsString('Cross-site SSE authorization requests are not allowed.', $body);
        $this->assertNull($result->getCookie('mercureAuthorization'));
    }

    public function testMercureRouteAllowsCrossSiteBootstrapWhenFetchMetadataCheckIsDisabled(): void
    {
        $result = $this->mercureBootstrapResponse('cross-site', rejectCrossSiteBootstrap: false);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertInstanceOf(Cookie::class, $result->getCookie('mercureAuthorization'));
    }

    private function controllerResponse(
        ?string $origin,
        ?string $accept,
        ?BrokerAdapterInterface $adapter = null,
    ): ResponseInterface {
        $request  = single_service('request');
        $response = single_service('response');
        $logger   = service('logger');

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $request->removeHeader('Origin');
        $request->removeHeader('Accept');
        $request->removeHeader('Sec-Fetch-Site');

        if ($origin !== null) {
            $request->setHeader('Origin', $origin);
        }

        if ($accept !== null) {
            $request->setHeader('Accept', $accept);
        }

        if ($adapter !== null) {
            FrameworkServices::injectMock('sseBrokerAdapter', $adapter);
        }

        try {
            $controller = new SseController();
            $controller->initController($request, $response, $logger);

            return $controller->stream();
        } finally {
            if ($adapter !== null) {
                FrameworkServices::resetSingle('sseBrokerAdapter');
            }
        }
    }

    private function mercureBootstrapResponse(
        ?string $secFetchSite,
        bool $rejectCrossSiteBootstrap = true,
    ): ResponseInterface {
        $config                           = new Sse();
        $config->broker                   = 'mercure';
        $config->rejectCrossSiteBootstrap = $rejectCrossSiteBootstrap;
        $config->mercure                  = [
            'hubUrl'        => 'http://mercure/.well-known/mercure',
            'publicHubUrl'  => 'https://example.test/.well-known/mercure',
            'topicPrefix'   => 'urn:example:sse:',
            'publisherKey'  => 'publisher-test-secret-at-least-32-bytes',
            'subscriberKey' => 'subscriber-test-secret-at-least-32-bytes',
            'cookie'        => [
                'name'     => 'mercureAuthorization',
                'secure'   => true,
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ],
        ];
        $request  = single_service('request');
        $response = single_service('response');
        $logger   = service('logger');

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $superglobals = service('superglobals');
        $this->assertInstanceOf(Superglobals::class, $superglobals);
        $previousGet = $superglobals->getGetArray();
        $superglobals->setGetArray(['channels' => 'public.news']);
        $request->removeHeader('Origin');
        $request->removeHeader('Accept');
        $request->removeHeader('Sec-Fetch-Site');

        if ($secFetchSite !== null) {
            $request->setHeader('Sec-Fetch-Site', $secFetchSite);
        }

        try {
            FrameworkServices::injectMock(
                'sseBrokerAdapter',
                new BasicBrokerAdapter(endpoint: new MercureSubscriptionEndpoint($config)),
            );

            $controller = new SseController();
            $controller->initController($request, $response, $logger);

            return $controller->stream();
        } finally {
            $superglobals->setGetArray($previousGet);
            $request->removeHeader('Sec-Fetch-Site');
            FrameworkServices::resetSingle('sseBrokerAdapter');
        }
    }
}
