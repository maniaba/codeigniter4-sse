<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Endpoint;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorProviderInterface;
use Maniaba\CodeIgniterSse\Contracts\PreflightSubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\HTTP\AcceptHeaderNegotiator;
use Maniaba\CodeIgniterSse\HTTP\SseResponseFactory;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;

final readonly class LocalSseSubscriptionEndpoint implements PreflightSubscriptionEndpointInterface, ChannelSelectorValidatorProviderInterface
{
    public function __construct(
        private SseConnectionManager $manager,
        private bool $requireAcceptHeader = true,
        private ?SseResponseFactory $responseFactory = null,
        private ?ChannelSelectorValidatorInterface $channelSelectorValidator = null,
    ) {
    }

    public function channelSelectorValidator(): ChannelSelectorValidatorInterface
    {
        return $this->channelSelectorValidator ?? new ChannelNameValidator();
    }

    public function preflight(RequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        if (
            ! $this->requireAcceptHeader
            || (new AcceptHeaderNegotiator())->preferred(
                strtolower($request->getHeaderLine('Accept')),
                ['stream' => 'text/event-stream'],
            ) === 'stream'
        ) {
            return null;
        }

        return $this->error(
            $response,
            406,
            'not_acceptable',
            'This endpoint requires Accept: text/event-stream.',
        );
    }

    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface {
        $factory = $this->responseFactory ?? new SseResponseFactory($response);

        $response = $factory->create(
            function (SseOutputInterface $output) use ($channels): void {
                $this->manager->stream($output, $channels);
            },
        );
        $response->appendHeader('Vary', 'Accept');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
    ): ResponseInterface {
        return $response
            ->setStatusCode($status)
            ->setJSON([
                'error' => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ])
            ->setHeader('Cache-Control', 'private, no-store')
            ->appendHeader('Vary', 'Accept')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
