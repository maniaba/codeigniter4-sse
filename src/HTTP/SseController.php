<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Maniaba\CodeIgniterSse\Config\Sse as SseConfig;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\PreflightSubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\Exception\InvalidOriginException;
use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;
use Maniaba\CodeIgniterSse\Factory\AuthorizationFactory;
use Maniaba\CodeIgniterSse\Factory\BrokerFactory;
use Maniaba\CodeIgniterSse\Factory\ConnectionManagerFactory;
use Maniaba\CodeIgniterSse\Factory\MercureSubscriptionFactory;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;

final class SseController extends ResourceController
{
    public function __construct(
        private readonly ?SseConnectionManager $manager = null,
        private readonly ?SseResponseFactory $responseFactory = null,
        private readonly ?AuthorizationFactory $authorizations = null,
        private readonly ?ConnectionManagerFactory $connectionManagers = null,
        private readonly ?MercureSubscriptionFactory $mercureSubscriptions = null,
        private readonly ?SseConfig $config = null,
        private readonly ?BrokerFactory $brokers = null,
    ) {
    }

    public function stream(): ResponseInterface
    {
        $config = $this->config ?? SseConfig::discover();
        $origin = $this->request->getHeaderLine('Origin');
        $cors   = new CorsPolicy($config->allowedOrigins, $config->withCredentials);

        try {
            $cors->assertAllowed($origin);
        } catch (InvalidOriginException $exception) {
            return $this->error(403, 'origin_forbidden', $exception->getMessage());
        }

        $endpoint = $this->subscriptionEndpoint($config);

        if ($endpoint instanceof PreflightSubscriptionEndpointInterface) {
            $preflight = $endpoint->preflight($this->request, $this->response);

            if ($preflight !== null) {
                return $cors->apply($preflight, $origin);
            }
        }

        try {
            $channels = $this->authorizeChannels($config);
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

        return $cors->apply(
            $endpoint->respond($this->request, $this->response, $channels),
            $origin,
        );
    }

    /**
     * @return list<string>
     */
    private function authorizeChannels(SseConfig $config): array
    {
        $channels = (new ChannelRequestParser(
            $config->maxChannelsPerConnection,
            $config->allowPatternSubscriptions,
        ))->parse($this->request->getGet('channels'));

        $authorizations = $this->authorizations ?? new AuthorizationFactory();
        $userResolver   = $authorizations->userResolver($config);
        $authorization  = $authorizations->channelAuthorization($config);

        return $authorization->authorizeAll($userResolver->resolve(), $channels);
    }

    private function subscriptionEndpoint(SseConfig $config): SubscriptionEndpointInterface
    {
        if ($this->manager !== null) {
            return new LocalSseSubscriptionEndpoint(
                $this->manager,
                $config->requireAcceptHeader,
                $this->responseFactory,
            );
        }

        if ($this->connectionManagers !== null) {
            return new LocalSseSubscriptionEndpoint(
                $this->connectionManagers->create($config),
                $config->requireAcceptHeader,
                $this->responseFactory,
            );
        }

        if ($this->mercureSubscriptions !== null && $config->streamTransport() === 'mercure') {
            return new MercureSubscriptionEndpoint($config, $this->mercureSubscriptions);
        }

        if ($this->config === null && $this->brokers === null) {
            $adapter = service('sseBrokerAdapter');

            if ($adapter instanceof BrokerAdapterInterface) {
                return $adapter->subscriptionEndpoint();
            }
        }

        return ($this->brokers ?? new BrokerFactory())->subscriptionEndpoint($config);
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
