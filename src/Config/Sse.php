<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;
use LogicException;
use Maniaba\CodeIgniterSse\Authorization\NullUserResolver;
use Maniaba\CodeIgniterSse\Authorization\PublicChannelAuthorizer;
use Maniaba\CodeIgniterSse\Broker\InMemory\InMemoryBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Null\NullBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;
use Maniaba\CodeIgniterSse\HTTP\SseController;

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
        'allowPatternSubscriptions'  => false,
        'clientName'                 => null,
        'streamContext'              => [],
    ];

    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_MERCURE = [
        'hubUrl'                       => 'http://127.0.0.1:3000/.well-known/mercure',
        'publicHubUrl'                 => 'http://127.0.0.1:3000/.well-known/mercure',
        'topicPrefix'                  => 'urn:codeigniter4-sse:',
        'private'                      => true,
        'authorizeSubscribers'         => true,
        'publisherJwt'                 => null,
        'publisherKey'                 => null,
        'subscriberKey'                => null,
        'publisherAlgorithm'           => 'HS256',
        'subscriberAlgorithm'          => 'HS256',
        'publisherTokenTtl'            => 300,
        'subscriberTokenTtl'           => 3600,
        'publisherTopicSelectors'      => null,
        'allowGlobalPublisherSelector' => false,
        'connectTimeout'               => 2.5,
        'timeout'                      => 5.0,
        'verifyTls'                    => true,
        'maxPayloadBytes'              => 1_048_576,
        'cookie'                       => [
            'name'     => 'mercureAuthorization',
            'domain'   => '',
            'path'     => '/.well-known/mercure',
            'secure'   => true,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ],
    ];

    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_ROUTE = [
        'enabled'    => true,
        'path'       => 'sse',
        'name'       => 'sse.stream',
        'controller' => SseController::class,
        'method'     => 'stream',
        'filters'    => [],
        'options'    => [],
    ];

    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_TOOLBAR = [
        'enabled'   => true,
        'brokers'   => ['*'],
        'maxEvents' => 100,
    ];

    /**
     * Route configuration used by package route discovery.
     *
     * @var array{
     *     enabled?: bool,
     *     path?: string,
     *     name?: string|null,
     *     controller?: class-string,
     *     method?: string,
     *     filters?: list<string>|string|null,
     *     options?: array<string, mixed>
     * }
     */
    public array $route = self::DEFAULT_ROUTE;

    public string $broker = 'redis';

    /**
     * @var array<string, array{
     *     factory?: BrokerAdapterFactoryInterface|callable(): BrokerAdapterFactoryInterface|class-string<BrokerAdapterFactoryInterface>,
     *     adapter?: BrokerAdapterInterface|callable(self, mixed): BrokerAdapterInterface|class-string<BrokerAdapterInterface>,
     *     shared?: bool
     * }>
     */
    public array $brokers = [
        'redis' => [
            'factory' => RedisBrokerAdapterFactory::class,
        ],
        'mercure' => [
            'factory' => MercureBrokerAdapterFactory::class,
        ],
        'memory' => [
            'factory' => InMemoryBrokerAdapterFactory::class,
            'shared'  => true,
        ],
        'null' => [
            'factory' => NullBrokerAdapterFactory::class,
            'shared'  => true,
        ],
    ];

    public string $channelPrefix          = 'app:sse:';
    public int $retryMilliseconds         = 3000;
    public int $heartbeatInterval         = 15;
    public int $maxConnectionSeconds      = 300;
    public int $maxChannelsPerConnection  = 20;
    public bool $emitConnectedEvent       = true;
    public bool $requireAcceptHeader      = true;
    public bool $rejectCrossSiteBootstrap = true;

    /**
     * CodeIgniter Debug Toolbar publisher tracing.
     *
     * `brokers` accepts concrete broker names from `$brokers` or `*` for all.
     *
     * @var array{
     *     enabled?: bool,
     *     brokers?: list<string>|string,
     *     maxEvents?: int
     * }
     */
    public array $toolbar = self::DEFAULT_TOOLBAR;

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

    /**
     * Redis adapter options.
     *
     * @var array<string, mixed>
     */
    public array $redis = self::DEFAULT_REDIS;

    /**
     * Mercure Hub, authorization and HTTP publisher options.
     *
     * @var array<string, mixed>
     */
    public array $mercure = self::DEFAULT_MERCURE;

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

        $route = $this->route();

        if ($route['enabled'] && trim($route['path'], " /\t\n\r\0\x0B") === '') {
            throw new InvalidArgumentException('The enabled SSE route must not be empty.');
        }

        if ($route['enabled'] && ! class_exists($route['controller'])) {
            throw new InvalidArgumentException(
                sprintf('SSE route controller "%s" does not exist.', $route['controller']),
            );
        }

        if ($route['enabled'] && $route['method'] === '') {
            throw new InvalidArgumentException('The enabled SSE route method must not be empty.');
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

        $toolbar = $this->toolbar();

        if ($toolbar['maxEvents'] < 1 || $toolbar['maxEvents'] > 1000) {
            throw new InvalidArgumentException(
                'SSE toolbar maxEvents must be between 1 and 1000.',
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

    /**
     * @return array<string, mixed>
     */
    public function mercure(): array
    {
        return array_replace_recursive(self::DEFAULT_MERCURE, $this->mercure);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     path: string,
     *     name: string|null,
     *     controller: string,
     *     method: string,
     *     filters: list<string>|string|null,
     *     options: array<string, mixed>
     * }
     */
    public function route(): array
    {
        $route = array_replace_recursive(self::DEFAULT_ROUTE, $this->route);

        return [
            'enabled'    => (bool) $route['enabled'],
            'path'       => (string) $route['path'],
            'name'       => is_string($route['name']) && $route['name'] !== '' ? $route['name'] : null,
            'controller' => (string) $route['controller'],
            'method'     => (string) $route['method'],
            'filters'    => $this->normalizeRouteFilters($route['filters']),
            'options'    => $this->normalizeRouteOptions($route['options']),
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     brokers: list<string>,
     *     maxEvents: int
     * }
     */
    public function toolbar(): array
    {
        $toolbar = array_replace(self::DEFAULT_TOOLBAR, $this->toolbar);

        return [
            'enabled'   => (bool) $toolbar['enabled'],
            'brokers'   => $this->normalizeToolbarBrokers($toolbar['brokers']),
            'maxEvents' => (int) $toolbar['maxEvents'],
        ];
    }

    /**
     * @return list<string>|string|null
     */
    private function normalizeRouteFilters(mixed $filters): array|string|null
    {
        if ($filters === null || is_string($filters)) {
            return $filters;
        }

        if (! is_array($filters)) {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $filter): string => is_string($filter) ? trim($filter) : '', $filters),
            static fn (string $filter): bool => $filter !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRouteOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $name => $value) {
            if (is_string($name)) {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeToolbarBrokers(mixed $brokers): array
    {
        if (is_string($brokers)) {
            $brokers = [$brokers];
        }

        if (! is_array($brokers)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $broker): string => is_string($broker) ? trim($broker) : '', $brokers),
            static fn (string $broker): bool => $broker !== '',
        )));
    }
}
