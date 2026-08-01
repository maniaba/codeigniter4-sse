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
| `requireAcceptHeader` | `true` | Require `Accept: text/event-stream` for PHP-streamed brokers. |

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
method `GET`. Direct frontend adapters open EventSource on this route. The
Mercure frontend adapter first requests JSON authorization from this route and
then connects directly to the authorized Hub URL.

## Stream behavior

| Property | Default | Purpose |
|---|---:|---|
| `retryMilliseconds` | `3000` | SSE reconnect delay hint sent to the browser. |
| `heartbeatInterval` | `15` | Seconds between heartbeat comments while idle. |
| `maxConnectionSeconds` | `300` | Finite lifetime of one HTTP stream. |
| `maxChannelsPerConnection` | `20` | Maximum unique requested logical channels. The raw `channels` query input is also bounded from this value before splitting. |
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
| `brokers` | built-in Redis, Mercure, memory, null definitions | Broker adapter or broker adapter factory map. |
| `channelPrefix` | `app:sse:` | Prefix added to logical Redis channels. |

## Choosing Redis or Mercure

Use Redis when the deployment has a controlled number of simultaneous users
and PHP-FPM capacity is sized for one open SSE response per connected browser.
This is the simplest topology: CodeIgniter subscribes to Redis Pub/Sub and
streams events directly from the `/sse` route.

Use Mercure when the application expects larger concurrency, more active
users, or frequent reconnect bursts. With Mercure, PHP only authorizes the
requested channels and publishes events to the Hub. The browser's long-lived
EventSource connection is held by Mercure, not by a PHP worker. This keeps
normal page and API requests from competing with idle SSE streams for the
same PHP-FPM pool.

Recommended rule of thumb:

| Deployment shape | Recommended broker | Reason |
|---|---|---|
| Local development, small internal tools, bounded traffic | `redis` | Fewer moving parts and easy debugging. |
| Multi-instance PHP application with moderate SSE usage | `redis` or `mercure` | Redis works if PHP worker capacity is planned; Mercure reduces worker pressure. |
| Public application, many concurrent users, multi-tenant dashboards, high reconnect churn | `mercure` | The Hub is built to own long-lived SSE connections and lets PHP handle short requests. |

Publishing code does not change when switching between Redis and Mercure. The
server-side `broker` and the browser adapter must match.

`memory` is useful for isolated tests in one PHP process. It cannot carry a
message between separate HTTP requests or workers. `null` is a message sink:
it discards published events but an enabled SSE route still keeps each stream
open until disconnect or maximum lifetime. Set `route['enabled'] = false` to
disable the HTTP endpoint.

`mercure` has a publisher but no PHP subscriber. Its broker adapter supplies a
Mercure HTTP subscription endpoint, so the package route issues subscriber
authorization instead of creating a PHP stream. See [Mercure Hub](mercure.md)
for the complete configuration.

Do not use an empty shared prefix when several applications publish to the
same Redis instance.

Redis Pub/Sub is global across numbered Redis databases. `redis['database']`
therefore does not separate SSE traffic; `channelPrefix` is the isolation
boundary.

Custom brokers are added by registering a new key in `brokers`. A broker
definition must provide exactly one of `factory` or `adapter`:

- `factory`: `BrokerAdapterFactoryInterface`, callable returning one, or class
  name implementing it;
- `adapter`: `BrokerAdapterInterface`, callable returning one, or class name
  implementing it.

The adapter owns publishing and the HTTP subscription endpoint. If the broker
can stream through PHP, implement `SubscriberAwareBrokerAdapterInterface` too.
See [Custom brokers](custom-brokers.md) for implementation examples and
troubleshooting.

```php
use App\Sse\CustomBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\InMemory\InMemoryBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Null\NullBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisBrokerAdapterFactory;

final class Sse extends BaseSse
{
    public string $broker = 'custom';

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
        'custom' => [
            'factory' => CustomBrokerAdapterFactory::class,
        ],
    ];
}
```

## Mercure

Mercure options live in one array, parallel to Redis. A complete Mercure setup
has four parts:

1. run a Mercure Hub with publisher and subscriber JWT keys;
2. select `public string $broker = 'mercure';`;
3. configure `hubUrl`, `publicHubUrl`, keys, topic prefix, and cookie options;
4. use `MercureSseAdapter` in the browser client.

Example application config:

```php
public string $broker = 'mercure';

public array $mercure = [
    'hubUrl'       => 'http://mercure/.well-known/mercure',
    'publicHubUrl' => 'https://app.example.com/.well-known/mercure',
    'topicPrefix'  => 'urn:storefront:sse:',
    'private'      => true,

    'publisherKey'  => null,
    'subscriberKey' => null,
    // Defaults to topicPrefix . '{channel}'.
    'publisherTopicSelectors' => null,
    'allowGlobalPublisherSelector' => false,

    'cookie' => [
        'name'     => 'mercureAuthorization',
        'domain'   => '',
        'path'     => '/.well-known/mercure',
        'secure'   => true,
        'httpOnly' => true,
        'sameSite' => 'Lax',
    ],
];
```

Use a literal absolute IRI prefix such as `urn:herceg:sse:`. Prefixes
containing wildcard or URI-template characters, such as
`https://example.com/{topic}`, are rejected because they can broaden Mercure
topic selectors.

Nested `.env` overrides are supported:

```dotenv
sse.mercure.publisherKey = replace-with-the-hub-publisher-key
sse.mercure.subscriberKey = replace-with-the-hub-subscriber-key
```

For local plain HTTP only, also set:

```dotenv
sse.mercure.cookie.secure = false
```

Keep Hub signing keys out of source control. HMAC JWT signing keys must be at
least 32 bytes. The package validates Mercure configuration when the Mercure
adapter factory builds the broker. The full key reference and deployment
guidance are in [Mercure Hub](mercure.md).

## Redis connection

Redis setup has five parts:

1. run Redis and make it reachable from PHP;
2. keep `public string $broker = 'redis';`;
3. set an application-specific `channelPrefix`;
4. configure the `redis` connection array or `.env` overrides;
5. use `RedisSseAdapter` in the browser client.

Minimal local configuration:

```php
public string $broker = 'redis';
public string $channelPrefix = 'myapp:sse:';

public array $redis = [
    'host'     => '127.0.0.1',
    'port'     => 6379,
    'database' => 0,
];
```

Equivalent `.env` overrides:

```dotenv
sse.broker = redis
sse.channelPrefix = myapp:sse:
sse.redis.host = 127.0.0.1
sse.redis.port = 6379
sse.redis.database = 0
```

Then verify the connection:

```bash
redis-cli ping
php spark sse:health-check
```

For the browser client, use the Redis adapter:

```javascript
import {
    RedisSseAdapter,
    SseClient,
} from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    adapter: new RedisSseAdapter(),
    channels: ['public.news'],
});
```

When Redis is selected, `/sse` is the long-lived SSE response. Configure the
web server and PHP-FPM as described in
[Streaming and deployment](deployment.md).

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
    'allowPatternSubscriptions'  => false,
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
| `deduplicationCapacity` | `1024` | Recent channel/event ID pairs retained to suppress duplicates from overlapping exact and pattern subscriptions. |
| `maxPayloadBytes` | `1048576` | Maximum serialized event or inbound RESP bulk string size. |
| `maxResponseElements` | `1024` | Maximum elements accepted in one RESP array. |
| `maxResponseDepth` | `8` | Maximum accepted RESP nesting depth. |
| `allowPatternSubscriptions` | `false` | Permit Redis-style pattern requests. |
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
| `rejectCrossSiteBootstrap` | `true` | Reject Mercure authorization bootstrap requests with `Sec-Fetch-Site: cross-site`. |

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

`rejectCrossSiteBootstrap` adds a Fetch Metadata check for browsers that send
`Sec-Fetch-Site`. It is an extra defense for the Mercure authorization request
that sets the HttpOnly subscriber cookie; it does not replace CORS, session
authentication, or channel authorization. Disable it only for trusted legacy or
non-browser clients that cannot send Fetch Metadata headers.
