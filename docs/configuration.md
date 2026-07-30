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
}
```

Scalar properties can also be overridden through `.env` using the `sse.`
prefix and the exact property name:

```dotenv
sse.redisHost = 127.0.0.1
sse.redisPort = 6379
sse.redisPassword =
sse.redisDatabase = 2
sse.channelPrefix = storefront:sse:
sse.heartbeatInterval = 15
sse.maxConnectionSeconds = 300
```

## HTTP route

| Property | Default | Purpose |
|---|---:|---|
| `routeEnabled` | `true` | Register the package route through CI4 discovery. |
| `route` | `sse` | Route path without a leading slash. |
| `routeName` | `sse.stream` | Name used by CI4 route lookup. |
| `routeFilters` | `[]` | CI4 filter aliases applied to the route. |
| `requireAcceptHeader` | `true` | Require `Accept: text/event-stream`. |

Example:

```php
final class Sse extends BaseSse
{
    public string $route = 'live/events';

    /** @var list<string> */
    public array $routeFilters = ['auth', 'sse-concurrency'];
}
```

Both names in this example are application-defined CI4 filter aliases.

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

`SseRoutes::register()` honors `routeEnabled`, so it is intended for automatic
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

## Broker

| Property | Default | Purpose |
|---|---:|---|
| `broker` | `redis` | `redis`, `memory`, or `null`. |
| `channelPrefix` | `app:sse:` | Prefix added to logical Redis channels. |
| `allowPatternSubscriptions` | `false` | Permit Redis-style pattern requests. |

`memory` is useful for isolated tests in one PHP process. It cannot carry a
message between separate HTTP requests or workers. `null` is a message sink:
it discards published events but an enabled SSE route still keeps each stream
open until disconnect or maximum lifetime. Set `routeEnabled = false` to
disable the HTTP endpoint.

Do not use an empty shared prefix when several applications publish to the
same Redis instance.

Redis Pub/Sub is global across numbered Redis databases. `redisDatabase`
therefore does not separate SSE traffic; `channelPrefix` is the isolation
boundary.

## Redis connection

| Property | Default | Purpose |
|---|---:|---|
| `redisScheme` | `tcp` | PHP stream scheme, normally `tcp` or `tls`. |
| `redisHost` | `127.0.0.1` | Redis hostname or IP address. |
| `redisPort` | `6379` | Redis port. |
| `redisUsername` | `null` | ACL username. |
| `redisPassword` | `null` | Password or ACL secret. |
| `redisDatabase` | `0` | Database selected after authentication; it does not isolate Pub/Sub channels. |
| `redisConnectTimeout` | `2.5` | Socket connect timeout in seconds. |
| `redisReadTimeout` | `2.5` | Timeout for complete Redis command/handshake reads. |
| `redisPollInterval` | `1.0` | Maximum idle poll before stream checks run. |
| `redisPingInterval` | `15.0` | Verify an otherwise idle subscribed socket with Redis PING. |
| `redisReconnectAttempts` | `2` | Publisher/subscriber transport reconnect attempts. |
| `redisReconnectDelayMilliseconds` | `250` | Delay between Redis reconnect attempts. |
| `redisDeduplicationCapacity` | `1024` | Recent event IDs retained to suppress duplicates after reconnects or overlapping subscriptions. |
| `redisMaxPayloadBytes` | `1048576` | Maximum serialized event or inbound RESP bulk string size. |
| `redisMaxResponseElements` | `1024` | Maximum elements accepted in one RESP array. |
| `redisMaxResponseDepth` | `8` | Maximum accepted RESP nesting depth. |
| `redisClientName` | `null` | Optional Redis connection name. |
| `redisStreamContext` | `[]` | PHP stream context options, usually under `ssl`. |

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

```dotenv
sse.redisUsername = sse_app
sse.redisPassword = change-me
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
    public string $redisScheme = 'tls';
    public int $redisPort = 6380;

    public array $redisStreamContext = [
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'cafile'           => '/etc/ssl/certs/redis-ca.pem',
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
