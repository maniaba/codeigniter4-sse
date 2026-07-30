<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidOriginException;

final readonly class CorsPolicy
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private array $allowedOrigins = [],
        private bool $withCredentials = true,
    ) {
    }

    public function assertAllowed(?string $origin): void
    {
        if ($origin === null || $origin === '') {
            return;
        }

        if (in_array($origin, $this->allowedOrigins, true)) {
            return;
        }

        if (! $this->withCredentials && in_array('*', $this->allowedOrigins, true)) {
            return;
        }

        throw new InvalidOriginException('The request origin is not allowed for this SSE endpoint.');
    }

    public function apply(ResponseInterface $response, ?string $origin): ResponseInterface
    {
        if ($origin === null || $origin === '') {
            return $response;
        }

        $allowOrigin = in_array($origin, $this->allowedOrigins, true) ? $origin : '*';
        $response->setHeader('Access-Control-Allow-Origin', $allowOrigin);
        $response->appendHeader('Vary', 'Origin');

        if ($this->withCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
