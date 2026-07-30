# Architecture

The package separates event publication, message transport, HTTP streaming,
and browser behavior.

```text
Application service / controller / worker
             │
             ▼
        PublisherInterface
             │
             ▼
      Redis Pub/Sub publisher
             │
             ▼
            Redis
             │
             ▼
      Redis Pub/Sub subscriber
             │
             ▼
  SSE connection and response adapter
             │
             ▼
      Browser EventSource
```

## Public application boundary

Normal application code uses the high-level service:

```php
service('sse')->publish($channel, $eventName, $data);
```

Advanced code can depend on `PublisherInterface` and provide an
`EventInterface` directly. Neither path exposes Redis connection objects or
the SSE response implementation.

This boundary allows publishers in HTTP requests, queue workers, Spark
commands, and other PHP processes to use the same API.

## Event envelope

Every published message is serialized to a versioned envelope:

```json
{
  "id": "01985f0d-8f00-7a11-8b22-0123456789ab",
  "event": "order.updated",
  "channel": "users.42",
  "data": {
    "orderId": 918,
    "status": "paid"
  },
  "occurredAt": "2026-07-30T19:10:00+00:00",
  "version": 1
}
```

The event ID supports deduplication and future durable adapters. The schema
version permits compatible envelope evolution. With the current Redis Pub/Sub
adapter, an ID does not imply storage or replay.

## Logical and physical channels

Application and browser code use logical names:

```text
users.42
orders.918
public.news
```

The Redis adapter adds the configured prefix:

```text
myapp:sse:users.42
myapp:sse:orders.918
```

Authorization happens against the logical name before that mapping. A browser
cannot use the HTTP query to subscribe to arbitrary Redis infrastructure
channels.

## Publisher and subscriber connections

Redis Pub/Sub switches a subscribed connection into subscription mode.
Publisher and subscriber operations therefore use separate stream
connections. Long-lived subscribers are never borrowed by normal application
publishing.

The internal Redis client speaks the RESP2 protocol over PHP stream sockets.
It supports direct TCP or TLS connections without adding PhpRedis or Predis as
a public dependency.

## Streaming loop

An SSE connection periodically regains control even when Redis has no new
messages. This is required to:

- emit heartbeat comments;
- detect a disconnected client;
- enforce maximum connection lifetime;
- stop cleanly on read timeout or transport failure.

CodeIgniter 4.8 provides a native `SSEResponse` path. Older supported versions
use the package's compatibility response and the same stream contracts.

## Pub/Sub trade-offs

Redis Pub/Sub is a good fit for dashboards, notifications, cache
invalidation, and live UI hints. It provides broadcast but not persistence:

- messages published with no active subscriber are lost;
- a reconnect does not replay missed messages;
- delivery acknowledgements are not available;
- browser `Last-Event-ID` cannot reconstruct Pub/Sub history.

Store authoritative state elsewhere. When the browser reconnects, it can
refetch current state over normal HTTP.

A future Redis Streams adapter can add retention and replay without changing
the application publisher boundary, but its delivery contract will be
different and must be documented explicitly.

## Why the encoder is internal

The SSE wire format is small and is hidden behind a stream interface.
`eliashaeussler/sse` is not an installed dependency because:

- it is GPL-3.0-or-later while this package is MIT;
- its PSR-7 response does not provide live emission;
- coupling the core to one emitter would defeat the native CI4 4.8 bridge.

An application does not need to know which response adapter is active.
