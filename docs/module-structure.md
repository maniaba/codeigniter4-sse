# Module structure

The package keeps application publishing, broker transport, HTTP streaming,
and browser behavior separate.

```text
src/
├── Authorization/
├── Broker/
│   ├── Mercure/
│   └── Redis/
├── Commands/
├── Config/
├── Contracts/
├── Event/
├── Exception/
├── HTTP/
├── Stream/
└── Support/
```

## Public API

Application code should normally use:

```php
sse()->publish($channel, $eventName, $data);
```

For typed integrations, depend on:

- `PublisherInterface`
- `SubscriberInterface`
- `ChannelAuthorizerInterface`
- `UserResolverInterface`
- `EventInterface`
- `SerializerInterface`

## Broker layer

`Broker\Redis` contains the Pub/Sub implementation and the RESP socket client.
Publisher and subscriber connections are separate because Redis subscriptions
are blocking.

`Broker\Mercure` contains the HTTP publisher, topic mapper, JWT issuer, and
Hub configuration. Mercure has no PHP subscriber because browsers subscribe
directly to the Hub.

`InMemoryBroker` is for tests and one-process examples. `NullBroker` is useful
when applications want the API enabled without delivering live events.

## HTTP layer

`HTTP\SseController` parses the channel request, resolves the current user,
and authorizes every channel. It either starts the Redis-backed PHP stream or
returns Mercure bootstrap data and an HttpOnly subscriber cookie.

`HTTP\SseResponseFactory` selects the output implementation at runtime:

```text
Current package streaming response
    └── LegacySseResponse
```

## Stream layer

`SseConnectionManager` owns the long-running stream loop. It sends retry
configuration, the optional connected event, broker events, idle heartbeats,
and maximum-lifetime shutdown.

## Browser asset

`resources/js/sse-client.js` is a dependency-free wrapper around native
`EventSource`. It is published to the host application by:

```bash
php spark sse:install
```
