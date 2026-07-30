<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Maniaba\CodeIgniterSse\Config\Sse as SseConfig;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\Exception\InvalidOriginException;
use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;
use Maniaba\CodeIgniterSse\Factory\AuthorizationFactory;
use Maniaba\CodeIgniterSse\Factory\ConnectionManagerFactory;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;

final class SseController extends ResourceController
{
    public function __construct(
        private readonly ?SseConnectionManager $manager = null,
        private readonly ?SseResponseFactory $responseFactory = null,
        private readonly ?AuthorizationFactory $authorizations = null,
        private readonly ?ConnectionManagerFactory $connectionManagers = null,
    ) {
    }

    public function stream(): ResponseInterface
    {
        $config = SseConfig::discover();
        $origin = $this->request->getHeaderLine('Origin');
        $cors   = new CorsPolicy($config->allowedOrigins, $config->withCredentials);

        try {
            $cors->assertAllowed($origin);
        } catch (InvalidOriginException $exception) {
            return $this->error(403, 'origin_forbidden', $exception->getMessage());
        }

        if ($config->requireAcceptHeader && ! $this->acceptsEventStream()) {
            return $cors->apply(
                $this->error(
                    406,
                    'not_acceptable',
                    'This endpoint requires Accept: text/event-stream.',
                ),
                $origin,
            );
        }

        try {
            $channels = (new ChannelRequestParser(
                $config->maxChannelsPerConnection,
                $config->allowPatternSubscriptions,
            ))->parse($this->request->getGet('channels'));

            $authorizations = $this->authorizations ?? new AuthorizationFactory();
            $userResolver   = $authorizations->userResolver($config);
            $authorization  = $authorizations->channelAuthorization($config);

            $channels = $authorization->authorizeAll($userResolver->resolve(), $channels);
        } catch (InvalidChannelException|InvalidChannelRequestException $exception) {
            return $cors->apply(
                $this->error(400, 'invalid_channels', $exception->getMessage()),
                $origin,
            );
        } catch (UnauthorizedChannelException $exception) {
            return $cors->apply(
                $this->error(403, 'channel_forbidden', $exception->getMessage()),
                $origin,
            );
        }

        $manager = $this->manager
            ?? ($this->connectionManagers ?? new ConnectionManagerFactory())->create($config);
        $factory = $this->responseFactory ?? new SseResponseFactory($this->response);

        $response = $factory->create(
            static function (SseOutputInterface $output) use ($manager, $channels): void {
                $manager->stream($output, $channels);
            },
        );
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $cors->apply($response, $origin);
    }

    private function acceptsEventStream(): bool
    {
        $accept = strtolower($this->request->getHeaderLine('Accept'));

        if ($accept === '') {
            return false;
        }

        foreach (explode(',', $accept) as $mediaRange) {
            $mediaType = trim(explode(';', $mediaRange, 2)[0]);

            if ($mediaType === 'text/event-stream' || $mediaType === '*/*') {
                return true;
            }
        }

        return false;
    }

    private function error(int $status, string $code, string $message): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setJSON([
                'error' => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ]);
    }
}
