# Configuration

The package ships with `Maniaba\CodeIgniterSse\Config\Sse`. Create an
application config class only when defaults need to be changed:

```php
<?php

declare(strict_types=1);

namespace Config;

use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $channelPrefix = 'storefront:sse:';
    public int $heartbeatInterval = 15;
    public int $maxConnectionSeconds = 300;

    public array $redis = [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'database' => 2,
    ];
}
```

## HTTP route

| Property | Default | Purpose |
|---|---:|---|
| `route['enabled']` | `true` | Register the package route through CI4 discovery. |
| `route['path']` | `sse` | Route path without a leading slash. |
| `route['name']` | `sse.stream` | Name used by CI4 route lookup. |
| `route['controller']` | `SseController::class` | Controller class used by the route. |
| `route['method']` | `stream` | Controller method used by the route. |
| `route['filters']` | `[]` | CI4 filter aliases applied to the route. |
| `route['options']` | `[]` | Additional CI4 route options. |
| `requireAcceptHeader` | `true` | Require `Accept: text/event-stream`. |

Example:

```php
final class Sse extends BaseSse
{
    public array $route = [
        'path'    => 'live/events',
        'name'    => 'sse.stream',
        'filters' => ['auth', 'sse-concurrency'],
        'options' => [
            'priority' => 100,
        ],
    ];
}
```

Both names in this example are application-defined CI4 filter aliases.

Set `route['enabled'] = false` when the application registers the SSE endpoint
manually in `app/Config/Routes.php`.

Private streams should use both:

- an application authentication filter;
- a rate/concurrency filter keyed by authenticated subject and, where useful,
  client address.

The second filter should cap simultaneously open streams and abusive reconnect
churn without rejecting the expected reconnect after
`maxConnectionSeconds`. Channel authorization remains mandatory even when an
auth route filter is present.

When route auto-registration is disabled, the application can register its own
route explicitly:

```php
$routes->get(
    'live/events',
    '\\' . \Maniaba\CodeIgniterSse\HTTP\SseController::class . '::stream',
    ['filter' => 'auth|sse-concurrency'],
);
```

`SseRoutes::register()` honors `route['enabled']`, so it is intended for automatic
package discovery rather than bypassing a disabled route. Keep the request
method `GET`; browser `EventSource` cannot issue a custom POST request.

## Stream behavior

| Property | Default | Purpose |
|---|---:|---|
| `retryMilliseconds` | `3000` | SSE reconnect delay hint sent to the browser. |
| `heartbeatInterval` | `15` | Seconds between heartbeat comments while idle. |
| `maxConnectionSeconds` | `300` | Finite lifetime of one HTTP stream. |
| `maxChannelsPerConnection` | `20` | Maximum unique requested logical channels. |
| `emitConnectedEvent` | `true` | Send `sse.connected` after opening the stream. |

The browser automatically opens a new stream after the server reaches
`maxConnectionSeconds`. A finite lifetime releases PHP workers, rotates Redis
subscriptions, and allows deployments to drain old processes.

Keep `heartbeatInterval` lower than the shortest idle timeout between the
application and browser. A heartbeat is an SSE comment and does not invoke
application message handlers.

## Debug toolbar

When CodeIgniter Debug Toolbar is enabled, the package adds an `SSE Events`
collector through module discovery. It lists SSE publish calls triggered during
the current HTTP request.

The collector records metadata only:

- channel;
- event name;
- event ID;
- publish status;
- payload size in bytes;
- top-level data keys;
- publisher class or error message.

It does not display payload values, because event data can contain user data or
other sensitive fields.

| Property | Default | Purpose |
|---|---:|---|
| `toolbar['enabled']` | `true` | Enable publisher tracing during web debug requests. |
| `toolbar['brokers']` | `['*']` | Broker names tracked by the toolbar, or `*` for all configured brokers. |
| `toolbar['maxEvents']` | `100` | Maximum number of publish records kept for one request. |

Track only selected brokers:

```php
final class Sse extends BaseSse
{
    public array $toolbar = [
        'brokers' => ['redis', 'custom'],
    ];
}
```

Disable tracing when the application does not need it:

```php
final class Sse extends BaseSse
{
    public array $toolbar = [
        'enabled' => false,
    ];
}
```

## Broker

| Property | Default | Purpose |
|---|---:|---|
| `broker` | `redis` | Active key from `brokers`. |
| `brokers` | built-in Redis, memory, null definitions | Publisher/subscriber class or factory map. |
| `channelPrefix` | `app:sse:` | Prefix added to logical Redis channels. |
| `allowPatternSubscriptions` | `false` | Permit Redis-style pattern requests. |

`memory` is useful for isolated tests in one PHP process. It cannot carry a
message between separate HTTP requests or workers. `null` is a message sink:
it discards published events but an enabled SSE route still keeps each stream
open until disconnect or maximum lifetime. Set `route['enabled'] = false` to
disable the HTTP endpoint.

Do not use an empty shared prefix when several applications publish to the
same Redis instance.

Redis Pub/Sub is global across numbered Redis databases. `redis['database']`
therefore does not separate SSE traffic; `channelPrefix` is the isolation
boundary.

Custom brokers are added by registering a new key in `brokers`:

```php
use App\Sse\CustomPublisher;
use App\Sse\CustomSubscriber;

final class Sse extends BaseSse
{
    public string $broker = 'custom';

    public array $brokers = [
        'redis' => [
            'publisher'  => \Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher::class,
            'subscriber' => \Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber::class,
        ],
        'memory' => [
            'publisher'  => \Maniaba\CodeIgniterSse\Broker\InMemoryBroker::class,
            'subscriber' => \Maniaba\CodeIgniterSse\Broker\InMemoryBroker::class,
            'shared'     => true,
        ],
        'null' => [
            'publisher'  => \Maniaba\CodeIgniterSse\Broker\NullBroker::class,
            'subscriber' => \Maniaba\CodeIgniterSse\Broker\NullBroker::class,
            'shared'     => true,
        ],
        'custom' => [
            'publisher'  => CustomPublisher::class,
            'subscriber' => CustomSubscriber::class,
        ],
    ];
}
```

When a broker needs application services or constructor arguments, use factory
closures:

```php
public array $brokers = [
    'redis' => [
        'publisher'  => \Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher::class,
        'subscriber' => \Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber::class,
    ],
    'custom' => [
        'publisher'  => static fn (): PublisherInterface => service('customSsePublisher'),
        'subscriber' => static fn (): SubscriberInterface => service('customSseSubscriber'),
    ],
];
```

## Redis connection

All Redis options live under one config property:

```php
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
    'clientName'                 => null,
    'streamContext'              => [],
];
```

| Array key | Default | Purpose |
|---|---:|---|
| `scheme` | `tcp` | PHP stream scheme, normally `tcp` or `tls`. |
| `host` | `127.0.0.1` | Redis hostname or IP address. |
| `port` | `6379` | Redis port. |
| `username` | `null` | ACL username. |
| `password` | `null` | Password or ACL secret. |
| `database` | `0` | Database selected after authentication; it does not isolate Pub/Sub channels. |
| `connectTimeout` | `2.5` | Socket connect timeout in seconds. |
| `readTimeout` | `2.5` | Timeout for complete Redis command/handshake reads. |
| `pollInterval` | `1.0` | Maximum idle poll before stream checks run. |
| `pingInterval` | `15.0` | Verify an otherwise idle subscribed socket with Redis PING. |
| `reconnectAttempts` | `2` | Publisher/subscriber transport reconnect attempts. |
| `reconnectDelayMilliseconds` | `250` | Delay between Redis reconnect attempts. |
| `deduplicationCapacity` | `1024` | Recent event IDs retained to suppress duplicates after reconnects or overlapping subscriptions. |
| `maxPayloadBytes` | `1048576` | Maximum serialized event or inbound RESP bulk string size. |
| `maxResponseElements` | `1024` | Maximum elements accepted in one RESP array. |
| `maxResponseDepth` | `8` | Maximum accepted RESP nesting depth. |
| `clientName` | `null` | Optional Redis connection name. |
| `streamContext` | `[]` | PHP stream context options, usually under `ssl`. |

Publisher and subscriber connections are always separate. The subscriber uses
the poll interval to return control for heartbeats, client disconnect checks,
and maximum lifetime enforcement. When no Redis frames arrive, periodic PING
checks detect half-open sockets and trigger the bounded reconnect path.

The payload and RESP limits protect long-running PHP workers from unexpectedly
large broker frames. Raise them only when the application intentionally sends
larger events. When pattern subscriptions are enabled, `channelPrefix` must
not contain Redis glob metacharacters.

### Redis ACL

Supply both username and password when ACL authentication is enabled:

```php
public array $redis = [
    'username' => 'sse_app',
    'password' => 'change-me',
];
```

Use environment secrets rather than committing credentials to the config
file.

The Redis identity needs only the commands used by the adapter, including
connection setup, `PUBLISH`, and channel subscription commands. Restrict its
accessible channel patterns to the configured application prefix where Redis
ACL policy allows it.

### TLS

Configure TLS and certificate validation in the PHP config class:

```php
final class Sse extends BaseSse
{
    public array $redis = [
        'scheme' => 'tls',
        'port'   => 6380,

        'streamContext' => [
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => '/etc/ssl/certs/redis-ca.pem',
            ],
        ],
    ];
}
```

Do not disable peer verification in production.

## Authorization

| Property | Default | Purpose |
|---|---|---|
| `channelAuthorizer` | `PublicChannelAuthorizer::class` | Authorizes each requested logical channel. |
| `userResolver` | `NullUserResolver::class` | Resolves the current authenticated user or `null`. |

The secure default allows only `public.*`. Set both class names when private
channels are used:

```php
public string $channelAuthorizer = \App\Sse\ChannelAuthorizer::class;
public string $userResolver = \App\Sse\UserResolver::class;
```

Applications using CodeIgniter Shield can use the packaged resolver:

```php
public string $userResolver = \Maniaba\CodeIgniterSse\Authorization\ShieldUserResolver::class;
```

Both classes must implement their package contracts. See
[Channels and authorization](channels-and-authorization.md).

## CORS and credentials

| Property | Default | Purpose |
|---|---:|---|
| `allowedOrigins` | `[]` | Exact cross-origin frontend origins. |
| `withCredentials` | `true` | Allow credentialed CORS responses. |

Same-origin requests do not require an allowlist entry because browsers omit
the cross-origin `Origin` case this policy is intended to control.

For a separate frontend origin:

```php
public array $allowedOrigins = [
    'https://app.example.com',
];

public bool $withCredentials = true;
```

When credentials are enabled, `*` is not a valid allowed origin. Cookie domain,
`Secure`, and `SameSite` settings must also permit the browser to send the
session cookie.
