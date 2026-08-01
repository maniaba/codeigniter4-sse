# Module structure

The package keeps application publishing, broker transport, HTTP streaming,
and browser behavior separate.

```text
src/
├── Authorization/
├── Broker/
│   ├── InMemory/
│   ├── Local/
│   ├── Mercure/
│   ├── Null/
│   └── Redis/
├── Commands/
├── Config/
├── Contracts/
├── Endpoint/
├── Event/
├── Exception/
├── Factory/
├── Health/
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
- `BrokerAdapterInterface`
- `BrokerAdapterFactoryInterface`
- `SubscriptionEndpointInterface`
- `ChannelAuthorizerInterface`
- `UserResolverInterface`
- `EventInterface`
- `SerializerInterface`

## Broker layer

`Broker\Redis` contains the Pub/Sub implementation and the RESP socket client.
Publisher and subscriber connections are separate because Redis subscriptions
are blocking.

`Broker\Mercure` contains the HTTP publisher, topic mapper, JWT issuer, and
Hub configuration and subscription endpoint. Mercure has no PHP subscriber
because browsers subscribe directly to the Hub.

`Broker\InMemory` is for tests and one-process examples. `Broker\Null` is
useful when applications want the API enabled without delivering live events.
`Broker\Local` contains the reusable local adapter used by PHP-stream brokers.

Custom broker implementations should live in their own folder and enter the
package through `BrokerAdapterInterface` or `BrokerAdapterFactoryInterface`.
See [Custom brokers](custom-brokers.md).

## HTTP layer

`HTTP\SseController` parses the channel request, resolves the current user,
authorizes every channel, and delegates the response to the active broker
adapter's subscription endpoint.

`Endpoint\LocalSseSubscriptionEndpoint` is the generic PHP stream endpoint
used by local subscriber-aware brokers. It also provides the short JSON
descriptor that points the browser back to that stream. Broker-specific
endpoints, such as Mercure's Hub authorization endpoint, live beside their broker
implementation and return the same generic descriptor shape.

`HTTP\SseResponseFactory` selects the output implementation at runtime:

```text
Current package streaming response
    └── LegacySseResponse
```

## Stream layer

`SseConnectionManager` owns the long-running stream loop. It sends retry
configuration, the optional connected event, broker events, idle heartbeats,
and maximum-lifetime shutdown. `BrowserEventEncoder` keeps the JSON payload
sent to browser EventSource clients separate from broker transport
serialization.

## Browser asset

`resources/js/sse-client.js` wraps native `EventSource`; the files under
`resources/js/adapters/` handle broker-specific connection resolution. They
are published to the host application by:

```bash
php spark sse:install
```
