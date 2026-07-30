<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\HTTP\ResponseInterface;
use LogicException;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\Stream\SseEncoder;
use UnexpectedValueException;

final class SseResponseFactory
{
    public function __construct(
        private readonly object $response,
        private readonly ?SseEncoder $encoder = null,
    ) {
    }

    /**
     * @param callable(SseOutputInterface): void $callback
     */
    public function create(callable $callback): ResponseInterface
    {
        $response = $this->response;

        if (is_callable([$response, 'eventStream'])) {
            $eventStream = [$response, 'eventStream'];
            $native      = $eventStream(
                static function (object $output) use ($callback): void {
                    $callback(new CodeIgniterSseOutput($output));
                },
            );

            if (! $native instanceof ResponseInterface) {
                throw new UnexpectedValueException(
                    'CodeIgniter eventStream() must return a ResponseInterface instance.',
                );
            }

            return $native;
        }

        $getProtocolVersion = [$response, 'getProtocolVersion'];

        if (! is_callable($getProtocolVersion)) {
            throw new LogicException('The CodeIgniter response must provide getProtocolVersion().');
        }

        $protocolVersion = $getProtocolVersion();

        if (! is_string($protocolVersion)) {
            throw new UnexpectedValueException(
                'The CodeIgniter response protocol version must be a string.',
            );
        }

        return (new LegacySseResponse($callback, $this->encoder))
            ->setProtocolVersion($protocolVersion);
    }
}
