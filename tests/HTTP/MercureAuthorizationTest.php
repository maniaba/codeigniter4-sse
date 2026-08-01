<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\Config\Factories;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Superglobals;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services as FrameworkServices;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\HTTP\SseController;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Support\Tests\Adapter\BasicBrokerAdapter;
use Support\Tests\Config\Fixtures\MercureTestUserResolver;
use Support\Tests\Config\Fixtures\MercureUserChannelAuthorizer;

/**
 * @internal
 */
final class MercureAuthorizationTest extends CIUnitTestCase
{
    /**
     * @param list<string> $channels
     * @param list<string> $expectedAuthorizedChannels
     */
    #[DataProvider('provideMercureRouteAuthorizesUserChannelCombinations')]
    public function testMercureRouteAuthorizesUserChannelCombinations(
        ?object $user,
        array $channels,
        int $expectedStatus,
        array $expectedAuthorizedChannels,
    ): void {
        $config                    = $this->mercureConfig();
        $config->channelAuthorizer = MercureUserChannelAuthorizer::class;
        $config->userResolver      = MercureTestUserResolver::class;

        MercureTestUserResolver::$user          = $user;
        MercureUserChannelAuthorizer::$attempts = [];

        $request  = single_service('request');
        $response = single_service('response');
        $logger   = service('logger');

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $request->removeHeader('Origin');
        $request->removeHeader('Accept');
        $request->setHeader('Accept', 'application/json');

        $superglobals = service('superglobals');
        $this->assertInstanceOf(Superglobals::class, $superglobals);
        $previousGet = $superglobals->getGetArray();
        $superglobals->setGetArray(['channels' => implode(',', $channels)]);

        try {
            Factories::injectMock('config', 'Sse', $config);
            FrameworkServices::injectMock(
                'sseBrokerAdapter',
                new BasicBrokerAdapter(endpoint: new MercureSubscriptionEndpoint($config)),
            );

            $controller = new SseController();
            $controller->initController($request, $response, $logger);
            $result = $controller->stream();
            $body   = $result->getBody();

            $this->assertSame($expectedStatus, $result->getStatusCode());
            $this->assertIsString($body);

            if ($expectedStatus === 200) {
                $this->assertMercureAuthorizationResponse($result, $body, $expectedAuthorizedChannels);
            } else {
                $this->assertStringContainsString('channel_forbidden', $body);
                $this->assertNull($result->getCookie('mercureAuthorization'));
            }

            $this->assertAuthorizationAttempts($user, $expectedAuthorizedChannels, $expectedStatus);
        } finally {
            $superglobals->setGetArray($previousGet);
            FrameworkServices::resetSingle('sseBrokerAdapter');
            Factories::reset('config');
            MercureTestUserResolver::$user          = null;
            MercureUserChannelAuthorizer::$attempts = [];
        }
    }

    /**
     * @return iterable<string, array{object|null, list<string>, int, list<string>}>
     */
    public static function provideMercureRouteAuthorizesUserChannelCombinations(): iterable
    {
        $user42 = self::user('42', ['acme', 'beta'], ['checkout', 'billing']);
        $user7  = self::user('7', ['beta'], ['support']);
        $admin  = self::user('1', ['root'], ['ops'], 'admin');
        $guest  = null;

        yield 'guest can subscribe to one public channel' => [
            $guest,
            ['public.news'],
            200,
            ['public.news'],
        ];

        yield 'guest can subscribe to multiple public channels' => [
            $guest,
            ['public.news', 'public.status'],
            200,
            ['public.news', 'public.status'],
        ];

        yield 'guest cannot subscribe to a user channel' => [
            $guest,
            ['users.42'],
            403,
            [],
        ];

        yield 'guest cannot mix public and user channels' => [
            $guest,
            ['public.news', 'users.42'],
            403,
            ['public.news'],
        ];

        yield 'user can subscribe to own user channel' => [
            $user42,
            ['users.42'],
            200,
            ['users.42'],
        ];

        yield 'user cannot subscribe to another user channel' => [
            $user42,
            ['users.7'],
            403,
            [],
        ];

        yield 'user can combine own user channel and public channel' => [
            $user42,
            ['users.42', 'public.news'],
            200,
            ['users.42', 'public.news'],
        ];

        yield 'user cannot combine own and foreign user channel' => [
            $user42,
            ['users.42', 'users.7'],
            403,
            ['users.42'],
        ];

        yield 'user can subscribe to one assigned tenant' => [
            $user42,
            ['tenants.acme'],
            200,
            ['tenants.acme'],
        ];

        yield 'user can subscribe to multiple assigned tenants' => [
            $user42,
            ['tenants.acme', 'tenants.beta'],
            200,
            ['tenants.acme', 'tenants.beta'],
        ];

        yield 'user cannot subscribe to unassigned tenant' => [
            $user42,
            ['tenants.gamma'],
            403,
            [],
        ];

        yield 'user cannot mix assigned and unassigned tenants' => [
            $user42,
            ['tenants.acme', 'tenants.gamma'],
            403,
            ['tenants.acme'],
        ];

        yield 'user can subscribe to assigned project' => [
            $user42,
            ['projects.checkout'],
            200,
            ['projects.checkout'],
        ];

        yield 'user cannot subscribe to unassigned project' => [
            $user42,
            ['projects.support'],
            403,
            [],
        ];

        yield 'user can combine own user tenant project and public channels' => [
            $user42,
            ['users.42', 'tenants.acme', 'projects.checkout', 'public.news'],
            200,
            ['users.42', 'tenants.acme', 'projects.checkout', 'public.news'],
        ];

        yield 'different user can subscribe to their own project and tenant' => [
            $user7,
            ['users.7', 'tenants.beta', 'projects.support'],
            200,
            ['users.7', 'tenants.beta', 'projects.support'],
        ];

        yield 'different user cannot subscribe to first users private channel' => [
            $user7,
            ['users.42'],
            403,
            [],
        ];

        yield 'admin can subscribe to admin channel' => [
            $admin,
            ['admin.metrics'],
            200,
            ['admin.metrics'],
        ];

        yield 'non-admin cannot subscribe to admin channel' => [
            $user42,
            ['admin.metrics'],
            403,
            [],
        ];

        yield 'admin can combine admin and public channels' => [
            $admin,
            ['admin.metrics', 'public.status'],
            200,
            ['admin.metrics', 'public.status'],
        ];

        yield 'admin still cannot subscribe to unrelated user channel' => [
            $admin,
            ['users.42'],
            403,
            [],
        ];

        yield 'user id comparison is exact' => [
            $user42,
            ['users.042'],
            403,
            [],
        ];

        yield 'unknown private namespace is denied' => [
            $user42,
            ['orders.100'],
            403,
            [],
        ];

        yield 'duplicate authorized channels are emitted once' => [
            $user42,
            ['users.42', 'public.news', 'users.42', 'public.news'],
            200,
            ['users.42', 'public.news'],
        ];
    }

    /**
     * @param list<string> $expectedAuthorizedChannels
     */
    private function assertMercureAuthorizationResponse(
        ResponseInterface $result,
        string $body,
        array $expectedAuthorizedChannels,
    ): void {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $topics  = array_map(
            static fn (string $channel): string => 'urn:example:sse:' . $channel,
            $expectedAuthorizedChannels,
        );

        $this->assertSame('https://example.test/.well-known/mercure', $decoded['hub']);
        $this->assertSame($topics, $decoded['topics']);
        $this->assertIsInt($decoded['expiresAt']);

        $cookie = $result->getCookie('mercureAuthorization');
        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHTTPOnly());

        $tokenParts = explode('.', $cookie->getValue());
        $this->assertCount(3, $tokenParts);
        $claims = $this->decodeJwtPayload($tokenParts[1]);

        $this->assertSame('maniaba/codeigniter4-sse', $claims['iss']);
        $this->assertSame('mercure', $claims['aud']);
        $this->assertSame('mercure-subscriber', $claims['sub']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $claims['jti']);
        $this->assertSame(
            ['subscribe' => $topics],
            $claims['mercure'],
        );
    }

    /**
     * @param list<string> $authorizedBeforeFailure
     */
    private function assertAuthorizationAttempts(
        ?object $user,
        array $authorizedBeforeFailure,
        int $expectedStatus,
    ): void {
        $attempts = MercureUserChannelAuthorizer::$attempts;
        $this->assertNotSame([], $attempts);

        foreach ($attempts as $attempt) {
            $this->assertSame($user, $attempt['user']);
        }

        if ($expectedStatus === 200) {
            $this->assertSame(
                $authorizedBeforeFailure,
                array_column($attempts, 'channel'),
            );

            return;
        }

        $attemptedChannels = array_column($attempts, 'channel');
        $this->assertSame($authorizedBeforeFailure, array_slice($attemptedChannels, 0, count($authorizedBeforeFailure)));
        $this->assertCount(count($authorizedBeforeFailure) + 1, $attemptedChannels);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $payload): array
    {
        $padding = strlen($payload) % 4;

        if ($padding !== 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function mercureConfig(): Sse
    {
        $config                           = new Sse();
        $config->broker                   = 'mercure';
        $config->maxChannelsPerConnection = 20;
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

        return $config;
    }

    /**
     * @param list<string> $tenantIds
     * @param list<string> $projectIds
     */
    private static function user(
        string $id,
        array $tenantIds = [],
        array $projectIds = [],
        string $role = 'user',
    ): object {
        return (object) [
            'id'         => $id,
            'tenantIds'  => $tenantIds,
            'projectIds' => $projectIds,
            'role'       => $role,
        ];
    }
}
