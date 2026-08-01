<?php

declare(strict_types=1);

namespace Config;

use Maniaba\CodeIgniterSse\Authorization\NullUserResolver;
use Maniaba\CodeIgniterSse\Authorization\PublicChannelAuthorizer;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;
use Maniaba\CodeIgniterSse\HTTP\SseController;

class Sse extends BaseSse
{
    /**
     * Automatic package route.
     *
     * GET /sse?channels=public.news
     *
     * Redis returns the SSE stream. Mercure returns short-lived Hub
     * authorization for the browser client.
     *
     * @var array<string, mixed>
     */
    public array $route = [
        'enabled'    => true,
        'path'       => 'sse',
        'name'       => 'sse.stream',
        'controller' => SseController::class,
        'method'     => 'stream',
        'filters'    => [
            // 'session',
            // 'throttle:sse',
        ],
        'options' => [
            // 'priority' => 100,
        ],
    ];

    /**
     * Built-in brokers: redis, mercure, memory, null.
     *
     * Redis is a good default when the application can afford one PHP worker
     * per open SSE stream. For larger environments and applications with many
     * concurrent users, prefer Mercure so long-lived browser connections are
     * handled by the Hub instead of PHP-FPM.
     */
    public string $broker = 'redis';

    /**
     * Prefix used for physical Redis Pub/Sub channels.
     */
    public string $channelPrefix = 'app:sse:';

    /**
     * Browser reconnect hint sent in SSE frames.
     */
    public int $retryMilliseconds = 3000;

    /**
     * Heartbeat comment interval while the stream is idle.
     */
    public int $heartbeatInterval = 15;

    /**
     * Close long-lived PHP requests periodically; the browser reconnects.
     */
    public int $maxConnectionSeconds = 300;

    /**
     * Limit how many logical channels one browser connection can request.
     */
    public int $maxChannelsPerConnection = 20;

    /**
     * Sends an initial "sse.connected" event after authorization succeeds.
     */
    public bool $emitConnectedEvent = true;

    /**
     * Require Accept: text/event-stream on the stream endpoint.
     */
    public bool $requireAcceptHeader = true;

    /**
     * CodeIgniter Debug Toolbar publisher tracing.
     *
     * `brokers` accepts concrete broker names from `$brokers` or `*` for all.
     *
     * @var array<string, mixed>
     */
    public array $toolbar = [
        'enabled'   => true,
        'brokers'   => ['*'],
        'maxEvents' => 100,
    ];

    /**
     * Cross-origin frontend origins. Leave empty for same-origin usage.
     *
     * @var list<string>
     */
    public array $allowedOrigins = [
        // 'https://app.example.com',
    ];

    /**
     * Native EventSource cookie support for credentialed cross-origin streams.
     */
    public bool $withCredentials = true;

    /**
     * Replace these with application implementations for private channels.
     *
     * @var class-string<\Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface>
     */
    public string $channelAuthorizer = PublicChannelAuthorizer::class;

    /**
     * Use an application resolver, ShieldUserResolver::class, or another adapter.
     *
     * @var class-string<\Maniaba\CodeIgniterSse\Contracts\UserResolverInterface>
     */
    public string $userResolver = NullUserResolver::class;

    /**
     * Redis adapter options.
     *
     * @var array<string, mixed>
     */
    public array $redis = [
        'scheme'                     => 'tcp',
        'host'                       => '127.0.0.1',
        'port'                       => 6379,
        'username'                   => null,
        'password'                   => null,
        'database'                   => 0,
        'connectTimeout'             => 2.5,
        'readTimeout'                => 2.5,
        'pollInterval'               => 1.0,
        'pingInterval'               => 15.0,
        'reconnectAttempts'          => 2,
        'reconnectDelayMilliseconds' => 250,
        'deduplicationCapacity'      => 1024,
        'maxPayloadBytes'            => 1_048_576,
        'maxResponseElements'        => 1024,
        'maxResponseDepth'           => 8,
        'allowPatternSubscriptions'  => false,
        'clientName'                 => null,
        'streamContext'              => [],
    ];

    /**
     * Mercure Hub adapter options.
     *
     * The server-side URL can use the Docker service name while publicHubUrl
     * must be reachable by the browser. Keep private updates enabled unless
     * every configured channel is intentionally public.
     *
     * @var array<string, mixed>
     */
    public array $mercure = [
        'hubUrl'                  => 'http://127.0.0.1:3000/.well-known/mercure',
        'publicHubUrl'            => 'http://127.0.0.1:3000/.well-known/mercure',
        'topicPrefix'             => 'urn:codeigniter4-sse:',
        'private'                 => true,
        'authorizeSubscribers'    => true,
        'publisherJwt'            => null,
        'publisherKey'            => null,
        'subscriberKey'           => null,
        'publisherAlgorithm'      => 'HS256',
        'subscriberAlgorithm'     => 'HS256',
        'publisherTokenTtl'       => 300,
        'subscriberTokenTtl'      => 3600,
        'publisherTopicSelectors' => ['*'],
        'connectTimeout'          => 2.5,
        'timeout'                 => 5.0,
        'verifyTls'               => true,
        'maxPayloadBytes'         => 1_048_576,
        'cookie'                  => [
            'name'     => 'mercureAuthorization',
            'domain'   => '',
            'path'     => '/.well-known/mercure',
            'secure'   => true,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ],
    ];
}
