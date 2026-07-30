<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;
use LogicException;
use Maniaba\CodeIgniterSse\Authorization\NullUserResolver;
use Maniaba\CodeIgniterSse\Authorization\PublicChannelAuthorizer;
use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

class Sse extends BaseConfig
{
    /**
     * Register the package GET route automatically.
     */
    public bool $routeEnabled = true;

    public string $route     = 'sse';
    public string $routeName = 'sse.stream';

    /**
     * @var list<string>
     */
    public array $routeFilters = [];

    /**
     * redis, memory, or null.
     */
    public string $broker = 'redis';

    public string $channelPrefix           = 'app:sse:';
    public int $retryMilliseconds          = 3000;
    public int $heartbeatInterval          = 15;
    public int $maxConnectionSeconds       = 300;
    public int $maxChannelsPerConnection   = 20;
    public bool $allowPatternSubscriptions = false;
    public bool $emitConnectedEvent        = true;
    public bool $requireAcceptHeader       = true;

    /**
     * @var list<string>
     */
    public array $allowedOrigins = [];

    public bool $withCredentials = true;

    /**
     * @var class-string<ChannelAuthorizerInterface>
     */
    public string $channelAuthorizer = PublicChannelAuthorizer::class;

    /**
     * @var class-string<UserResolverInterface>
     */
    public string $userResolver = NullUserResolver::class;

    public string $redisScheme        = 'tcp';
    public string $redisHost          = '127.0.0.1';
    public int $redisPort             = 6379;
    public ?string $redisUsername     = null;
    public ?string $redisPassword     = null;
    public int $redisDatabase         = 0;
    public float $redisConnectTimeout = 2.5;
    public float $redisReadTimeout    = 2.5;

    /**
     * Maximum time stream_select() waits before returning control for
     * heartbeat and disconnect checks.
     */
    public float $redisPollInterval = 1.0;

    /**
     * Verify an otherwise idle subscribed socket with Redis PING.
     */
    public float $redisPingInterval = 15.0;

    public int $redisReconnectAttempts          = 2;
    public int $redisReconnectDelayMilliseconds = 250;
    public int $redisDeduplicationCapacity      = 1024;
    public int $redisMaxPayloadBytes            = 1_048_576;
    public int $redisMaxResponseElements        = 1024;
    public int $redisMaxResponseDepth           = 8;
    public ?string $redisClientName             = null;

    /**
     * PHP stream context options, normally under the "ssl" key.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $redisStreamContext = [];

    public function __construct()
    {
        parent::__construct();
        $this->validate();
    }

    public static function discover(): self
    {
        $config = config('Sse');

        if (! $config instanceof self) {
            throw new LogicException(
                'The discovered Sse configuration must extend ' . self::class . '.',
            );
        }

        $config->validate();

        return $config;
    }

    public function validate(): void
    {
        if (! in_array(strtolower($this->broker), ['redis', 'memory', 'null'], true)) {
            throw new InvalidArgumentException('SSE broker must be redis, memory, or null.');
        }

        if ($this->routeEnabled && trim($this->route, " /\t\n\r\0\x0B") === '') {
            throw new InvalidArgumentException('The enabled SSE route must not be empty.');
        }

        if ($this->retryMilliseconds < 0) {
            throw new InvalidArgumentException('SSE retryMilliseconds must not be negative.');
        }

        if ($this->heartbeatInterval < 1) {
            throw new InvalidArgumentException('SSE heartbeatInterval must be at least one second.');
        }

        if ($this->maxConnectionSeconds < 1) {
            throw new InvalidArgumentException('SSE maxConnectionSeconds must be at least one second.');
        }

        if ($this->maxChannelsPerConnection < 1 || $this->maxChannelsPerConnection > 100) {
            throw new InvalidArgumentException(
                'SSE maxChannelsPerConnection must be between 1 and 100.',
            );
        }

        if ($this->withCredentials && in_array('*', $this->allowedOrigins, true)) {
            throw new InvalidArgumentException(
                'A wildcard SSE origin cannot be combined with credentials.',
            );
        }
    }
}
