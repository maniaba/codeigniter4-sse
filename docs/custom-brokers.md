# Custom brokers

Custom brokers plug into the package through one boundary: a broker adapter.
Do not configure separate `publisher` and `subscriber` classes in
`Sse::$brokers`; that legacy shape is not supported.

## Required contracts

Every custom broker definition must resolve to `BrokerAdapterInterface`.

| Need | Contract |
|---|---|
| Publish application events and provide a subscribe endpoint | `BrokerAdapterInterface` |
| Build the adapter from configuration/services | `BrokerAdapterFactoryInterface` |
| Publish one event to the transport | `PublisherInterface` |
| Let the package stream through PHP | `SubscriberAwareBrokerAdapterInterface` plus `SubscriberInterface` |
| Return a broker-specific HTTP response | `SubscriptionEndpointInterface` |
| Run checks in `php spark sse:health-check` | `HealthCheckableInterface` |
| Accept non-standard channel selectors | `ChannelSelectorValidatorProviderInterface` |

The minimal adapter contract is:

```php
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;

final readonly class AcmeBrokerAdapter implements BrokerAdapterInterface
{
    public function __construct(
        private PublisherInterface $publisher,
        private SubscriptionEndpointInterface $endpoint,
    ) {
    }

    public function publisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function subscriptionEndpoint(): SubscriptionEndpointInterface
    {
        return $this->endpoint;
    }
}
```

## Recommended folder layout

Keep every broker-specific class in its own application folder:

```text
app/
└── Sse/
    └── Broker/
        └── Acme/
            ├── AcmeBrokerAdapter.php
            ├── AcmeBrokerAdapterFactory.php
            ├── AcmeConfig.php
            ├── AcmeConfigFactory.php
            ├── AcmePublisher.php
            ├── AcmeSubscriptionEndpoint.php
            └── AcmeSubscriber.php
```

`AcmeSubscriber` is needed only when the transport should be streamed by PHP.
Hub-style transports, where the browser connects directly to an external
service, usually need only a publisher and a subscription endpoint.

## Register the broker

Register the broker under a key in `app/Config/Sse.php`:

```php
use App\Sse\Broker\Acme\AcmeBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $broker = 'acme';

    public array $brokers = [
        'acme' => [
            'factory' => AcmeBrokerAdapterFactory::class,
        ],
    ];
}
```

If the application still needs the built-in brokers, keep their definitions in
the same array or merge them before `Sse::validate()` runs. The package default
definitions are shown in [Configuration](configuration.md#broker).

If the factory needs application services or constructor arguments, use a
callable factory provider:

```php
use App\Sse\Broker\Acme\AcmeBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;

public array $brokers = [
    'acme' => [
        'factory' => static fn (): BrokerAdapterFactoryInterface => new AcmeBrokerAdapterFactory(
            service('acmeSseClient'),
            env('sse.acme.endpoint'),
        ),
    ],
];
```

The callable receives no arguments. The returned factory receives `Sse` and
`BrokerBuildContext` when the broker is built.

## Broker-specific configuration

Keep custom transport options out of the core package config fields. A common
shape is to store broker-specific options beside the broker definition:

```php
public array $brokers = [
    'acme' => [
        'factory' => AcmeBrokerAdapterFactory::class,
        'options' => [
            'endpoint' => 'https://broker.example.com/sse',
            'token'    => null,
        ],
    ],
];
```

The package resolver ignores unknown keys such as `options`; the custom
factory may read them from `$config->brokers[$config->broker]`.

For non-trivial options, mirror the built-in Redis and Mercure adapters: put a
small config object and config factory in the broker folder.

```php
use Maniaba\CodeIgniterSse\Broker\Config\AbstractBrokerConfigFactory;
use Maniaba\CodeIgniterSse\Config\Sse;

final class AcmeConfigFactory extends AbstractBrokerConfigFactory
{
    public function create(Sse $config): AcmeConfig
    {
        $definition = $config->brokers[$config->broker] ?? [];
        $options    = self::arrayOption($definition['options'] ?? null);

        return new AcmeConfig(
            endpoint: (string) ($options['endpoint'] ?? ''),
            token: self::nullableString($options['token'] ?? null),
        );
    }
}
```

## Implement the factory

`BrokerAdapterFactoryInterface` is the normal entry point for a custom broker:

```php
use App\Sse\Broker\Acme\AcmeBrokerAdapter;
use App\Sse\Broker\Acme\AcmePublisher;
use App\Sse\Broker\Acme\AcmeSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;

final readonly class AcmeBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function __construct(
        private AcmeClient $client,
        private string $publicEndpoint,
    ) {
    }

    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        return new AcmeBrokerAdapter(
            new AcmePublisher($this->client, $context->serializer),
            new AcmeSubscriptionEndpoint($this->publicEndpoint),
        );
    }
}
```

`BrokerBuildContext` provides the package serializer and event factory. Use the
serializer when the external transport should receive the standard package
event envelope.

## Implement publishing

```php
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;

final readonly class AcmePublisher implements PublisherInterface
{
    public function __construct(
        private AcmeClient $client,
        private SerializerInterface $serializer,
    ) {
    }

    public function publish(string $channel, EventInterface $event): void
    {
        $this->client->publish(
            $channel,
            $this->serializer->serialize($channel, $event),
        );
    }
}
```

The package validates channels before application code calls
`sse()->publish(...)`, but a custom publisher is still a transport boundary.
Validate or constrain anything that becomes a remote topic, URL, header, or
query parameter.

## Implement the subscription endpoint

For Hub-style brokers, return bootstrap data that the browser client can use
to connect to the external service:

```php
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorProviderInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;

final readonly class AcmeSubscriptionEndpoint implements
    SubscriptionEndpointInterface,
    ChannelSelectorValidatorProviderInterface
{
    public function __construct(private string $publicEndpoint)
    {
    }

    public function channelSelectorValidator(): ChannelSelectorValidatorInterface
    {
        return new ChannelNameValidator();
    }

    public function respond(
        RequestInterface $request,
        ResponseInterface $response,
        array $channels,
    ): ResponseInterface {
        return $response
            ->setStatusCode(200)
            ->setJSON([
                'transport' => 'acme',
                'endpoint'  => $this->publicEndpoint,
                'channels'  => $channels,
            ])
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
```

The package authorizes channels before `respond()` is called. The endpoint
receives only approved channel selectors.

## PHP-stream brokers

If the broker should keep the browser connected to a PHP SSE response, the
adapter must also implement `SubscriberAwareBrokerAdapterInterface`. The
factory can reuse the built-in local endpoint:

```php
use Maniaba\CodeIgniterSse\Broker\LocalBrokerAdapter;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Endpoint\LocalSseSubscriptionEndpoint;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Maniaba\CodeIgniterSse\Stream\SseConnectionOptions;

final readonly class AcmeStreamBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        $publisher  = new AcmePublisher(service('acmeSseClient'), $context->serializer);
        $subscriber = new AcmeSubscriber(service('acmeSseClient'), $context->serializer);
        $manager    = new SseConnectionManager(
            $subscriber,
            $context->serializer,
            $context->events,
            SseConnectionOptions::fromConfig($config),
        );

        return new LocalBrokerAdapter(
            $publisher,
            $subscriber,
            new LocalSseSubscriptionEndpoint($manager, $config->requireAcceptHeader),
        );
    }
}
```

The subscriber must call `$onMessage` with `BrokerMessage` instances and must
regularly return control to `$onIdle` or `shouldStop` checks so disconnects,
heartbeats, and maximum lifetime can work.

## Custom channel selectors

By default the request parser accepts exact package channel names such as
`public.news` or `users.42`. If a broker supports selectors such as patterns,
the endpoint should implement `ChannelSelectorValidatorProviderInterface` and
return a validator that knows that broker's syntax.

Throw `InvalidChannelException` from the validator when the selector is not
allowed. Keep syntax validation in the broker folder; the core parser should
not know Redis, Mercure, or custom broker rules.

## Health checks

If the adapter implements `HealthCheckableInterface`, `php spark
sse:health-check` will render its result. Without that interface the command
prints a skipped result for the broker.

Use health checks for external dependencies: credentials, sockets, HTTP Hub
availability, TLS configuration, or required PHP extensions.

## When a custom broker does not work

Most failures map directly to a missing or wrong contract:

| Error or symptom | Fix |
|---|---|
| `must define either "factory" or "adapter"` | Add `factory` or `adapter` to `Sse::$brokers[$broker]`. |
| `adapter factory "..." does not exist` | Check namespace, Composer autoload, and class name. Run `composer dump-autoload`. |
| `factory must implement BrokerAdapterFactoryInterface` | Implement `create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface`. |
| `adapter must implement BrokerAdapterInterface` | Implement `publisher()` and `subscriptionEndpoint()`. |
| `does not provide a PHP subscriber` | Use a custom `SubscriptionEndpointInterface`, or implement `SubscriberAwareBrokerAdapterInterface` and `SubscriberInterface`. |
| Endpoint returns `400 invalid_channels` | The endpoint uses the default exact channel validator. Add `ChannelSelectorValidatorProviderInterface` if the broker supports custom selector syntax. |
| `sse:health-check` says skipped | Implement `HealthCheckableInterface` on the adapter if the broker should be checkable. |

Do not work around these errors by bypassing `SseController` or accepting
unvalidated channel strings. The adapter/factory contracts are the extension
point.
