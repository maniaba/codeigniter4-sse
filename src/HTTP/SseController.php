<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use LogicException;
use Maniaba\CodeIgniterSse\Config\Sse as SseConfig;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorProviderInterface;
use Maniaba\CodeIgniterSse\Contracts\PreflightSubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\Exception\InvalidOriginException;
use Maniaba\CodeIgniterSse\Exception\UnauthorizedChannelException;
use Maniaba\CodeIgniterSse\Factory\AuthorizationFactory;

final class SseController extends Controller
{
    use ResponseTrait;

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

        $endpoint = $this->subscriptionEndpoint($config);

        if ($endpoint instanceof PreflightSubscriptionEndpointInterface) {
            $preflight = $endpoint->preflight($this->request, $this->response);

            if ($preflight !== null) {
                return $cors->apply($preflight, $origin);
            }
        }

        try {
            $channels = $this->authorizeChannels($config, $endpoint);
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

        try {
            $response = $endpoint->respond($this->request, $this->response, $channels);
        } catch (InvalidOriginException $exception) {
            return $cors->apply(
                $this->error(403, 'origin_forbidden', $exception->getMessage()),
                $origin,
            );
        }

        return $cors->apply($response, $origin);
    }

    /**
     * @return list<string>
     */
    private function authorizeChannels(SseConfig $config, SubscriptionEndpointInterface $endpoint): array
    {
        $validator = $endpoint instanceof ChannelSelectorValidatorProviderInterface
            ? $endpoint->channelSelectorValidator()
            : null;
        $channels = (new ChannelRequestParser(
            $config->maxChannelsPerConnection,
            $validator,
        ))->parse($this->request->getGet('channels'));

        $authorizations = new AuthorizationFactory();
        $userResolver   = $authorizations->userResolver($config);
        $authorization  = $authorizations->channelAuthorization($config);

        return $authorization->authorizeAll($userResolver->resolve(), $channels);
    }

    private function subscriptionEndpoint(SseConfig $config): SubscriptionEndpointInterface
    {
        $adapter = service('sseBrokerAdapter', $config);

        if (! $adapter instanceof BrokerAdapterInterface) {
            throw new LogicException('The sseBrokerAdapter service must implement ' . BrokerAdapterInterface::class . '.');
        }

        return $adapter->subscriptionEndpoint();
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
