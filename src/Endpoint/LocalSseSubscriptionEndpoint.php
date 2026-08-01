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
        if (! $this->requireAcceptHeader || $this->acceptsEventStream($request)) {
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
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function acceptsEventStream(RequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        if ($accept === '') {
            return false;
        }

        $quality     = 0.0;
        $specificity = -1;

        foreach (explode(',', $accept) as $mediaRange) {
            $mediaRange = trim($mediaRange);

            if ($mediaRange === '') {
                continue;
            }

            [$mediaType, $rangeQuality, $rangeSpecificity] = $this->parseAcceptRange($mediaRange);

            if (
                ($mediaType !== 'text/event-stream' && $mediaType !== 'text/*' && $mediaType !== '*/*')
                || $rangeSpecificity < $specificity
            ) {
                continue;
            }

            if ($rangeSpecificity > $specificity || $rangeQuality > $quality) {
                $quality     = $rangeQuality;
                $specificity = $rangeSpecificity;
            }
        }

        return $specificity >= 0 && $quality > 0.0;
    }

    /**
     * @return array{0: string, 1: float, 2: int}
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

        return [$mediaType, $quality, $this->specificity($mediaType)];
    }

    private function parseQuality(string $value): float
    {
        if (preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $value) !== 1) {
            return 0.0;
        }

        return (float) $value;
    }

    private function specificity(string $mediaType): int
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
