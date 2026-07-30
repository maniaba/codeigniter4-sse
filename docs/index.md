# CodeIgniter SSE

CodeIgniter SSE adds Redis-backed Server-Sent Events to CodeIgniter 4
applications without coupling application code to Redis sockets or HTTP
streaming details.

```text
Application
   ↓ service('sse')->publish()
Publisher contract
   ↓
Redis Pub/Sub
   ↓
Subscriber contract
   ↓
CodeIgniter SSE response
   ↓
Browser EventSource
```

## What it provides

- a small `service('sse')->publish()` API;
- versioned JSON event envelopes with stable event IDs;
- Redis Pub/Sub over an internal RESP2 stream client;
- logical channel validation, limits, and server-side authorization;
- heartbeats, disconnect detection, and maximum connection lifetime;
- an SSE response adapter for current CodeIgniter applications;
- a dependency-free browser ES module.

## Requirements

- PHP 8.2 or newer with `ext-json`;
- CodeIgniter 4.7 or newer;
- a Redis server reachable over TCP or TLS.

PhpRedis and Predis are not required.

## Minimal example

Publish:

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

## Delivery model

Redis Pub/Sub is intentionally a live broadcast mechanism. It does not store
events and cannot replay messages missed while a browser is disconnected.
Business-critical state must remain in a database or another durable system;
an SSE event should normally tell the browser what changed, not become the
only record of the change.

## License

CodeIgniter SSE is released under the MIT License. The package contains its own
SSE wire encoder and does not require a GPL-licensed SSE runtime.
