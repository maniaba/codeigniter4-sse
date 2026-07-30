<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Superglobals;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\HTTP\SseController;
use Maniaba\CodeIgniterSse\HTTP\SseResponseFactory;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Psr\Log\LoggerInterface;
use Tests\Support\FixedEventIdGenerator;
use Tests\Support\RecordingSubscriber;

/**
 * @internal
 */
final class SseControllerTest extends CIUnitTestCase
{
    public function testRequiresEventStreamAcceptHeader(): void
    {
        $result = $this->controllerResponse(null, 'application/json');
        $body   = $result->getBody();

        $this->assertSame(406, $result->getStatusCode());
        $this->assertIsString($body);
        $this->assertStringContainsString('not_acceptable', $body);
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
            new JsonEventSerializer(),
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
                $manager,
                new SseResponseFactory($factoryResponse),
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

    private function controllerResponse(
        ?string $origin,
        ?string $accept,
        ?SseConnectionManager $manager = null,
        ?SseResponseFactory $responseFactory = null,
    ): ResponseInterface {
        $request  = single_service('request');
        $response = single_service('response');
        $logger   = service('logger');

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $request->removeHeader('Origin');
        $request->removeHeader('Accept');

        if ($origin !== null) {
            $request->setHeader('Origin', $origin);
        }

        if ($accept !== null) {
            $request->setHeader('Accept', $accept);
        }

        $controller = new SseController($manager, $responseFactory);
        $controller->initController($request, $response, $logger);

        return $controller->stream();
    }
}
