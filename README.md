# CodeIgniter SSE

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)
[![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7%2B-DD4814.svg)](https://codeigniter.com/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

`maniaba/codeigniter4-sse` adds Redis and Mercure Server-Sent Events to
CodeIgniter 4 applications. Application code publishes semantic events through
one CI4 API while the selected adapter handles delivery and streaming.

```text
                         ┌─ Redis Pub/Sub ── PHP SSE response ─┐
CodeIgniter application ┤                                    ├─ Browser
    sse()->publish()     └─ Mercure Hub ──────────────────────┘
```

The public API is independent of Redis and of the concrete HTTP streaming
implementation. Application code publishes events through package services and
does not need to manage Redis subscriptions or SSE frame emission directly.

## Requirements

- PHP 8.2 or newer
- CodeIgniter 4.7 or newer
- Redis server for the Redis adapter, or a Mercure Hub
- `ext-curl` when using Mercure

## Installation

Install the package:

```bash
composer require maniaba/codeigniter4-sse
php spark sse:install
```

Ensure that Redis is reachable:

```bash
redis-cli ping
php spark sse:health-check
```

The package speaks RESP2 over PHP stream sockets. It does not require
PhpRedis, Predis, or another Redis client package. The PHP JSON extension is
required.

Package routes, services, and Spark commands are discovered through
CodeIgniter's Composer package discovery. See
[Installation](docs/installation.md) when discovery is restricted in your
application.

## Quick start

Publish an event from a controller, domain service, listener, command, or queue
worker:

```php
sse()->publish(
    "users.{$userId}",
    'notification.created',
    [
        'title'   => 'Order paid',
        'orderId' => 918,
    ],
);
```

Open the stream for one or more logical channels:

```http
GET /sse?channels=users.42,orders.918
Accept: text/event-stream
```

Use the included framework-independent ES module:

```javascript
import {
    RedisSseAdapter,
    SseClient,
} from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    adapter: new RedisSseAdapter(),
    channels: [`users.${currentUserId}`],
    withCredentials: true,
});

live.on('notification.created', ({ data }) => {
    showToast(data.title);
    refreshOrder(data.orderId);
});

live.on('status', ({ status }) => {
    document.documentElement.dataset.liveStatus = status;
});

live.connect();
```

`SseClient` opens EventSource through the selected frontend adapter. When the
server broker changes, update the adapter class in the browser client.

The browser's native `EventSource` automatically reconnects when a connection
ends. With Redis, the package intentionally limits the PHP stream lifetime.
With Mercure, the browser streams directly from the Hub.

## Channel security

The built-in authorization policy permits only channels under `public.*`.
Every user, tenant, order, project, or other private channel is denied until
the application provides a `ChannelAuthorizerInterface` implementation.

```php
namespace App\Sse;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;

final class ChannelAuthorizer implements ChannelAuthorizerInterface
{
    public function authorize(?object $user, string $channel): bool
    {
        if (str_starts_with($channel, 'public.')) {
            return true;
        }

        if (
            $user !== null
            && preg_match('/^users\.(\d+)$/', $channel, $matches) === 1
        ) {
            return (string) $user->id === $matches[1];
        }

        return false;
    }
}
```

Register a matching `UserResolverInterface` when the application uses session,
Shield, JWT, or another authentication system. Channel names supplied by the
browser are logical names; clients never receive or control the internal Redis
prefix. In production, private SSE routes should also use application
authentication and per-user rate/concurrency filters to control open streams
and reconnect churn; filters do not replace per-channel authorization.

See [Channels and authorization](docs/channels-and-authorization.md) for the
complete setup.

## Configuration

Create `app/Config/Sse.php` when defaults need to be changed:

```php
<?php

declare(strict_types=1);

namespace Config;

use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $channelPrefix = 'myapp:sse:';
    public int $heartbeatInterval = 15;
    public int $maxConnectionSeconds = 300;
    public int $maxChannelsPerConnection = 20;
}
```

Redis connection details and scalar package options can be supplied through
`.env`. The exact options and production recommendations are documented under
[Configuration](docs/configuration.md).

## Delivery semantics

The first release deliberately uses raw Redis Pub/Sub:

- events are broadcast live;
- disconnected clients do not receive past events;
- there is no replay or guaranteed delivery;
- publisher and subscriber use separate Redis connections;
- event envelopes include an ID and schema version for future adapters.

Use a database, queue, or a future durable broker adapter when an event must
not be lost. Redis Streams and `Last-Event-ID` replay are outside the Pub/Sub
contract. Redis Pub/Sub is not isolated by numbered Redis databases, so every
application must use its own `channelPrefix`.

Mercure is available when long-lived streams should not occupy PHP workers:

```php
public string $broker = 'mercure';
```

Publishing remains unchanged. The `/sse` route becomes a short channel
authorization request that sets a topic-scoped HttpOnly JWT cookie, and the
browser client connects directly to the Hub:

```javascript
import {
    MercureSseAdapter,
    SseClient,
} from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    adapter: new MercureSseAdapter(),
    channels: [`users.${currentUserId}`],
});
```

Use the frontend adapter that matches the configured broker. Mercure's adapter
authorizes through the package route and then opens EventSource on the Hub.

Mercure can replay retained Hub history through `Last-Event-ID`. See
[Mercure Hub](docs/mercure.md) for Docker, signing keys, authorization,
cookies, CORS, and reverse-proxy configuration.


## Documentation

- [Installation](docs/installation.md)
- [Quick start](docs/quick-start.md)
- [Configuration](docs/configuration.md)
- [Architecture](docs/architecture.md)
- [Channels and authorization](docs/channels-and-authorization.md)
- [Browser client](docs/browser-client.md)
- [Mercure Hub](docs/mercure.md)
- [Streaming and deployment](docs/deployment.md)
- [Testing](docs/testing.md)
- [Troubleshooting](docs/troubleshooting.md)

## Testing

```bash
composer ci
```

Redis integration tests require a reachable test Redis instance and use a
dedicated prefix. See [Testing](docs/testing.md).

## License

CodeIgniter SSE is released under the [MIT License](LICENSE.md).
CodeIgniter-derived portions retain their notices in
[Third-party notices](THIRD_PARTY_NOTICES.md).
