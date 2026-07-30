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
     * Built-in brokers: redis, memory, null.
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
     * Keep disabled unless the application explicitly authorizes patterns.
     */
    public bool $allowPatternSubscriptions = false;

    /**
     * Sends an initial "sse.connected" event after authorization succeeds.
     */
    public bool $emitConnectedEvent = true;

    /**
     * Require Accept: text/event-stream on the stream endpoint.
     */
    public bool $requireAcceptHeader = true;

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
        'clientName'                 => null,
        'streamContext'              => [],
    ];
}
