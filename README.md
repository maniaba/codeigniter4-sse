# CodeIgniter SSE

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)
[![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7%2B-DD4814.svg)](https://codeigniter.com/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

`maniaba/codeigniter4-sse` adds Redis-backed Server-Sent Events to
CodeIgniter 4 applications. Application code publishes semantic events through
a CI4 service; the package authorizes logical channels, subscribes to Redis,
and streams the events to the browser.

```text
CodeIgniter application
    ↓ service('sse')->publish()
Redis Pub/Sub
    ↓
GET /sse?channels=...
    ↓
Browser EventSource / SseClient
```

The public API is independent of Redis and of the concrete HTTP streaming
implementation. Application code publishes events through package services and
does not need to manage Redis subscriptions or SSE frame emission directly.

## Requirements

- PHP 8.2 or newer
- CodeIgniter 4.7 or newer
- Redis server
- outbound TCP or TLS connectivity from PHP to Redis

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
service('sse')->publish(
    "users.{$userId}",
    'notification.created',
    [
        'title'   => 'Narudžba je plaćena',
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
import { SseClient } from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
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

The browser's native `EventSource` automatically reconnects when a connection
ends. The server intentionally limits connection lifetime so PHP workers are
released periodically.

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

## Why there is no external SSE runtime dependency

The package emits the SSE wire format itself. `eliashaeussler/sse` is
intentionally not a dependency: its GPL-3.0-or-later license is unsuitable for
this MIT package as a required dependency, and its PSR-7 response path is not a
real-time emitter.

Keeping the encoder behind the package stream contract keeps response details
transparent to application code.

## Documentation

- [Installation](docs/installation.md)
- [Quick start](docs/quick-start.md)
- [Configuration](docs/configuration.md)
- [Architecture](docs/architecture.md)
- [Channels and authorization](docs/channels-and-authorization.md)
- [Browser client](docs/browser-client.md)
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
