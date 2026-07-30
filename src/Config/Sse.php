<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use Closure;
use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;
use LogicException;
use Maniaba\CodeIgniterSse\Authorization\NullUserResolver;
use Maniaba\CodeIgniterSse\Authorization\PublicChannelAuthorizer;
use Maniaba\CodeIgniterSse\Broker\InMemoryBroker;
use Maniaba\CodeIgniterSse\Broker\NullBroker;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

class Sse extends BaseConfig
{
    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_REDIS = [
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
        'clientName'                 => null,
        'streamContext'              => [],
    ];

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

    public string $broker = 'redis';

    /**
     * @var array<string, array{
     *     publisher: callable(self): PublisherInterface|class-string<PublisherInterface>,
     *     subscriber: callable(self): SubscriberInterface|class-string<SubscriberInterface>,
     *     shared?: bool
     * }>
     */
    public array $brokers = [
        'redis' => [
            'publisher'  => RedisPublisher::class,
            'subscriber' => RedisSubscriber::class,
        ],
        'memory' => [
            'publisher'  => InMemoryBroker::class,
            'subscriber' => InMemoryBroker::class,
            'shared'     => true,
        ],
        'null' => [
            'publisher'  => NullBroker::class,
            'subscriber' => NullBroker::class,
            'shared'     => true,
        ],
    ];

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
     * @var class-string<ChannelAuthorizerInterface>|Closure(): ChannelAuthorizerInterface
     */
    public Closure|string $channelAuthorizer = PublicChannelAuthorizer::class;

    /**
     * @var class-string<UserResolverInterface>|Closure(): UserResolverInterface
     */
    public Closure|string $userResolver = NullUserResolver::class;

    /**
     * Redis adapter options.
     *
     * @var array<string, mixed>
     */
    public array $redis = self::DEFAULT_REDIS;

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
        if (! isset($this->brokers[$this->broker])) {
            throw new InvalidArgumentException(
                sprintf('SSE broker "%s" is not defined in Sse::$brokers.', $this->broker),
            );
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

    /**
     * @return array<string, mixed>
     */
    public function redis(): array
    {
        return array_replace_recursive(self::DEFAULT_REDIS, $this->redis);
    }
}
