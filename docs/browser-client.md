# Browser client

`resources/js/sse-client.js` is a dependency-free ES module that resolves the
configured stream through the package endpoint and then opens a native browser
`EventSource`.

It provides:

- named event dispatch;
- a global message handler;
- connection status notifications;
- safe JSON parsing;
- channel and custom query parameters;
- credential configuration;
- automatic stream resolution without exposing the configured broker;
- direct Mercure Hub transport with cookie authorization;
- explicit `connect()` and `close()`;
- a hook for an application-defined fallback.

After EventSource opens, native `EventSource` follows the server's SSE `retry`
value and reconnects automatically. Before it opens, transient bootstrap
network failures and retryable HTTP responses use a bounded exponential
backoff.

## Import

Install the browser client from npm when the application already has a frontend
build:

```bash
npm install @maniaba/codeigniter4-sse-browser
```

Then import it through the package export:

```javascript
import {
    SseClient,
    SseClientStatus,
} from '@maniaba/codeigniter4-sse-browser';
```

The module also provides a default export:

```javascript
import SseClient from '@maniaba/codeigniter4-sse-browser';
```

TypeScript projects can import the public types:

```typescript
import {
    SseClient,
    type SseClientOptions,
    type SseMessage,
    type SseStatusEvent,
} from '@maniaba/codeigniter4-sse-browser';
```

Without npm, copy the module to a public asset path or include it directly from
published package assets:

```javascript
import {
    SseClient,
    SseClientStatus,
} from '/vendor/codeigniter4-sse/sse-client.js';
```

The direct asset module also provides a default export:

```javascript
import SseClient from '/vendor/codeigniter4-sse/sse-client.js';
```

The package ships TypeScript declarations next to the module:

```text
resources/js/sse-client.d.ts
```

When `php spark sse:install` publishes browser assets, it copies both
`sse-client.js` and `sse-client.d.ts`.

## Constructor

```javascript
const live = new SseClient({
    endpoint: '/sse',
    channels: ['users.42', 'orders.918'],
    query: {
        locale: document.documentElement.lang,
        source: 'orders-page',
    },
    withCredentials: true,
    fallback: null,
});
```

| Option | Default | Description |
|---|---|---|
| `endpoint` | required | Absolute or browser-relative SSE URL. |
| `channels` | `[]` | Unique logical channel names, sent comma-separated. |
| `query` | `{}` | Object or `URLSearchParams` merged into the endpoint. |
| `withCredentials` | `true` | Enables cross-origin credentials for bootstrap and EventSource requests. |
| `fallback` | `null` | Optional bootstrap, connection-error, or unsupported-browser hook. |
| `eventSourceFactory` | native | Test seam for supplying an EventSource-compatible object. |
| `fetchFactory` | native | Test seam for the short stream-resolution request. |

Array query values are appended as repeated parameters. `null` and `undefined`
object values are omitted. The `channels` option wins over an existing
`channels` value in the endpoint or query object.

Do not use query parameters for bearer tokens or secrets.

## Automatic stream resolution

The browser does not select Redis, Mercure, or another broker. `connect()`
first requests a generic connection descriptor from `endpoint` with
`Accept: application/json`. The server decides where the EventSource should
connect.

For a PHP stream, `url: null` tells the client to reuse the original endpoint:

```json
{
  "url": null,
  "expiresAt": null
}
```

For an external Hub, the response supplies its URL, query parameters, and an
optional authorization expiry. The client treats both forms identically and
does not expose a transport option.

When Mercure is configured, `endpoint` remains the short CodeIgniter
authorization route:

```javascript
const live = new SseClient({
    endpoint: '/sse',
    channels: ['users.42'],
    withCredentials: true,
});
```

`connect()` fetches authorized Hub topics, receives the subscriber JWT through
an HttpOnly cookie, then opens EventSource directly against the returned Hub
URL. The authorization request is asynchronous; observe `status` when the UI
needs to know when the Hub connection reaches `open`.

The client refreshes a time-limited Mercure authorization before it expires.
Channel changes request a new token scoped to the new topic list. See
[Mercure Hub](mercure.md) for server, cookie, CORS, and reverse-proxy setup.

## Channels

Initial channels are passed to the constructor:

```javascript
const live = new SseClient({
    endpoint: '/sse',
    channels: ['users.42'],
});
```

Channels can also be changed later:

```javascript
live.subscribe('orders.918');
live.subscribe(['projects.15.activity', 'public.news']);

live.unsubscribe('orders.918');

live.setChannels(['users.42', 'orders.918']);
```

`subscribe()`, `unsubscribe()`, and `setChannels()` return the client for
chaining.

Native `EventSource` cannot change its URL after it is opened. When channels
change on an active client, the wrapper closes the current source, resolves a
new authorized stream URL, and opens it with the updated `channels` query
parameter. Removing the last channel closes the stream.

## Named events

Register a handler before or after connecting:

```javascript
const updateOrder = ({ data }) => {
    renderOrder(data);
};

live.on('order.updated', updateOrder);
live.connect();

// Later:
live.off('order.updated', updateOrder);
```

Calling `off('order.updated')` without a handler removes all handlers for that
event. `on()` and `off()` return the client for chaining.

The names `open` and `error` are reserved by native `EventSource`. `status` is
the wrapper lifecycle event and `message` is its global message event, so avoid
those four names for application events. Use namespaced domain names such as
`order.updated`.

## System events

When `emitConnectedEvent` is enabled, the server emits `sse.connected` with
the authorized channel list:

```javascript
live.on('sse.connected', ({ data }) => {
    console.log('Subscribed to', data.channels);
});
```

If an established stream loses its broker subscription and cannot recover,
the server attempts to emit `sse.error` before the browser reconnects:

```javascript
live.on('sse.error', ({ data }) => {
    if (data.retry) {
        showTemporaryLiveWarning();
    }
});
```

Connection state should still be taken from the `status` handler. Heartbeats
are SSE comments and never dispatch a JavaScript event.

## Message shape

The server sends a JSON envelope. Handlers receive a normalized object:

```javascript
live.on('order.updated', (message) => {
    console.log(message.id);
    console.log(message.event);
    console.log(message.channel);
    console.log(message.data);
    console.log(message.occurredAt);
    console.log(message.version);
});
```

The complete shape is:

```text
{
    id: '019...',
    event: 'order.updated',
    channel: 'orders.918',
    data: {
        orderId: 918,
        status: 'paid',
    },
    occurredAt: '2026-07-30T19:10:00+00:00',
    version: 1,
    raw: '{"id":"019..."}',
    parsed: true,
    parseError: null,
    originalEvent: MessageEvent,
}
```

Invalid JSON never throws from the EventSource callback. In that case:

- `data` contains the raw string;
- `parsed` is `false`;
- `parseError` contains the parsing error.

Application handlers should still validate payload fields before changing the
DOM.

## Global message handler

Use `message` or the convenience alias:

```javascript
live.on('message', (message) => {
    analytics.count(`sse.${message.event}`);
});

live.onMessage((message) => {
    debugSse(message);
});
```

The global handler receives unnamed SSE messages and each named event that the
client is observing through `on(eventName, handler)`. The native EventSource
API has no wildcard listener, so an unknown named server event cannot be
observed until its event name is registered.

## Connection status

Read the current state:

```javascript
if (live.status === SseClientStatus.OPEN) {
    showLiveIndicator();
}
```

Or subscribe:

```javascript
live.on('status', ({ status, previous, reason }) => {
    console.log({ status, previous, reason });
});
```

Possible values:

```text
idle
connecting
open
reconnecting
closed
unsupported
```

A finite server connection lifetime normally produces `reconnecting`, followed
by `open`. This is expected and does not require application retry code.

## Close and reconnect

```javascript
live.close();

// Existing event handlers are retained.
live.connect();
```

`connect()` is idempotent while a connection is active. `close()` prevents the
native source from reconnecting and releases its browser resources.

In a single-page application, close clients when their owning view unmounts.
Prefer one connection with several authorized channels over one EventSource
per widget.

## Fallback hook

The package does not prescribe polling. An application can start its own
fallback when EventSource is unavailable or a connection reports an error:

```javascript
const live = new SseClient({
    endpoint: '/sse',
    channels: ['public.status'],
    fallback: ({ reason, client }) => {
        if (reason === 'unsupported') {
            startStatusPolling();
            return;
        }

        if (reason === 'connection-error') {
            reportLiveConnectionProblem();

            // Optional: stop native reconnect before starting polling.
            // client.close();
            // startStatusPolling();
        }
    },
});
```

The hook receives:

```text
{
    reason,
    event,
    error,
    status,
    url,
    client,
}
```

Reasons are `unsupported`, `construction-error`, `connection-error`, and
`bootstrap-error`. The last value means the stream-resolution request failed
or returned invalid data. The hook runs once per reconnect cycle; a successful
native `open` resets it.

Network failures and HTTP `408`, `425`, `429`, or `5xx` bootstrap responses are
retried automatically with a capped exponential delay. `close()` cancels both
an in-flight bootstrap request and a scheduled retry. Other bootstrap failures
close the client because retrying cannot repair an invalid request or response
contract.

Browsers report the server's expected finite-lifetime rotation through the
same native `error` event as a network outage, so `connection-error` alone
must not immediately start polling. Debounce an outage fallback and cancel it
when the next `open` arrives. The `unsupported` reason is definitive and can
start a fallback immediately.

The client requires both `fetch` and `EventSource`. If no fallback is provided
and the browser has no `EventSource`, `connect()` throws a clear error. A
missing `fetch` is reported as `bootstrap-error`.

## Credentials and CORS

```javascript
const live = new SseClient({
    endpoint: 'https://api.example.com/sse',
    channels: ['users.42'],
    withCredentials: true,
});
```

For cross-origin cookies, both the bootstrap response and EventSource response
must return the exact allowed origin and the
`Access-Control-Allow-Credentials: true` header. Cookie `Domain`, `Secure`, and
`SameSite` attributes must also permit the request.

Standard browser EventSource does not accept arbitrary request headers.
