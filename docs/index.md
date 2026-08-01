# CodeIgniter SSE

CodeIgniter SSE adds Redis and Mercure Server-Sent Events to CodeIgniter 4
applications without coupling application code to broker sockets, Hub HTTP
requests, or streaming details.

```text
                         ┌─ Redis Pub/Sub ── PHP SSE response ─┐
Application publisher ──┤                                    ├─ EventSource
                         └─ Mercure Hub ──────────────────────┘
```

## What it provides

- a small `sse()->publish()` API;
- versioned JSON event envelopes with stable event IDs;
- Redis Pub/Sub over an internal RESP2 stream client;
- Mercure publishing, topic JWT authorization, and direct Hub streaming;
- custom broker adapters through stable package contracts;
- logical channel validation, limits, and server-side authorization;
- heartbeats, disconnect detection, and maximum connection lifetime;
- an SSE response adapter for current CodeIgniter applications;
- a dependency-free browser ES module.

## Requirements

- PHP 8.2 or newer with `ext-json`;
- CodeIgniter 4.7 or newer;
- Redis for the Redis adapter, or a Mercure Hub and `ext-curl`.

PhpRedis and Predis are not required.

## Minimal example

Publish:

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

Subscribe:

```javascript
import { SseClient } from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    channels: [`users.${currentUserId}`],
});

live.on('notification.created', ({ data }) => {
    showToast(data.title);
});

live.connect();
```

Private channels are denied until the application supplies an authorizer. Start
with the [Quick start](quick-start.md), then configure
[channels and authorization](channels-and-authorization.md).
If Redis or Mercure is not the right transport, see
[Custom brokers](custom-brokers.md).

## Delivery model

Redis Pub/Sub is intentionally a live broadcast mechanism. It does not store
events and cannot replay messages missed while a browser is disconnected.
Business-critical state must remain in a database or another durable system;
an SSE event should normally tell the browser what changed, not become the
only record of the change.

Mercure can retain events and replay them through `Last-Event-ID` according to
Hub history configuration. It also keeps long-lived streams outside PHP.
Publishing code remains identical; start with [Mercure Hub](mercure.md) when
that deployment model is preferred.

## License

CodeIgniter SSE is released under the MIT License. The package contains its own
SSE wire encoder and does not require a GPL-licensed SSE runtime.
