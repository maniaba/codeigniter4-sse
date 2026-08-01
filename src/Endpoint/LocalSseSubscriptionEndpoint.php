<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Endpoint;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorProviderInterface;
use Maniaba\CodeIgniterSse\Contracts\PreflightSubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
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
        if ($this->requestedRepresentation($request) !== null || ! $this->requireAcceptHeader) {
            return null;
        }

        return $this->error(
            $response,
            406,
            'not_acceptable',
            'This endpoint requires Accept: text/event-stream or application/json.',
        );
    }

    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface {
        if ($this->requestedRepresentation($request) === 'bootstrap') {
            return $response
                ->setStatusCode(200)
                ->setJSON([
                    'url'       => null,
                    'expiresAt' => null,
                ])
                ->setHeader('Cache-Control', 'private, no-store')
                ->appendHeader('Vary', 'Accept')
                ->setHeader('X-Content-Type-Options', 'nosniff');
        }

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

    private function requestedRepresentation(RequestInterface $request): ?string
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        if ($accept === '') {
            return null;
        }

        $bootstrapFound    = false;
        $bootstrapQuality  = 0.0;
        $bootstrapOrder    = PHP_INT_MAX;
        $streamQuality     = 0.0;
        $streamSpecificity = -1;
        $streamOrder       = PHP_INT_MAX;

        foreach (explode(',', $accept) as $order => $mediaRange) {
            $mediaRange = trim($mediaRange);

            if ($mediaRange === '') {
                continue;
            }

            [$mediaType, $rangeQuality] = $this->parseAcceptRange($mediaRange);

            if (
                $mediaType === 'application/json'
                && (! $bootstrapFound || $rangeQuality > $bootstrapQuality)
            ) {
                $bootstrapFound   = true;
                $bootstrapQuality = $rangeQuality;
                $bootstrapOrder   = $order;
            }

            $rangeSpecificity = $this->eventStreamSpecificity($mediaType);

            if (
                $rangeSpecificity > $streamSpecificity
                || ($rangeSpecificity === $streamSpecificity && $rangeQuality > $streamQuality)
            ) {
                $streamQuality     = $rangeQuality;
                $streamSpecificity = $rangeSpecificity;
                $streamOrder       = $order;
            }
        }

        $bootstrapAccepted = $bootstrapFound && $bootstrapQuality > 0.0;
        $streamAccepted    = $streamSpecificity >= 0 && $streamQuality > 0.0;

        if (! $bootstrapAccepted) {
            return $streamAccepted ? 'stream' : null;
        }

        if (! $streamAccepted) {
            return 'bootstrap';
        }

        if ($bootstrapQuality !== $streamQuality) {
            return $bootstrapQuality > $streamQuality ? 'bootstrap' : 'stream';
        }

        if ($streamSpecificity !== 2) {
            return $streamSpecificity < 2 ? 'bootstrap' : 'stream';
        }

        return $bootstrapOrder < $streamOrder ? 'bootstrap' : 'stream';
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function parseAcceptRange(string $mediaRange): array
    {
        $parts     = array_map(trim(...), explode(';', $mediaRange));
        $mediaType = array_shift($parts) ?? '';
        $quality   = 1.0;

        foreach ($parts as $parameter) {
            if (! str_starts_with($parameter, 'q=')) {
                continue;
            }

            $quality = $this->parseQuality(substr($parameter, 2));

            break;
        }

        return [$mediaType, $quality];
    }

    private function parseQuality(string $value): float
    {
        if (preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $value) !== 1) {
            return 0.0;
        }

        return (float) $value;
    }

    private function eventStreamSpecificity(string $mediaType): int
    {
        return match ($mediaType) {
            'text/event-stream' => 2,
            'text/*'            => 1,
            '*/*'               => 0,
            default             => -1,
        };
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
            ]);
    }
}
