# Troubleshooting

## The SSE route returns 404

Check package discovery:

```bash
composer dump-autoload
php spark routes
```

Confirm:

- Composer package discovery is enabled in `Config\Modules`;
- `route['enabled']` is `true`;
- `route` does not conflict with an earlier application route;
- the request URL includes the application's configured base path.

If the route was customized, use that path in the browser client.

## The endpoint returns 406

The default configuration requires:

```http
Accept: text/event-stream
```

Native browser `EventSource` sends it. Add the header when testing with curl:

```bash
curl -N \
    -H 'Accept: text/event-stream' \
    'http://localhost:8080/sse?channels=public.test'
```

Do not disable `requireAcceptHeader` merely to compensate for an incorrectly
configured proxy that strips request headers.

## The endpoint returns 400

The `channels` query parameter is missing or invalid.

Valid requests include:

```text
/sse?channels=public.news
/sse?channels=users.42,orders.918
/sse?channels[]=users.42&channels[]=orders.918
```

Check the channel syntax and `maxChannelsPerConnection`. Patterns are rejected
unless explicitly enabled.

## The endpoint returns 403

There are two common causes:

- one or more channels failed `ChannelAuthorizerInterface`;
- a cross-origin request is not in `allowedOrigins`.

Verify that `UserResolverInterface::resolve()` returns the expected
authenticated object. Do not “fix” a private-channel denial by switching to an
authorizer that permits every channel.

## Redis health check fails

Run:

```bash
php spark sse:health-check
```

Then verify:

- Redis `scheme`, `host`, and `port`;
- container or firewall networking;
- ACL username and password;
- selected Redis database;
- CA path and peer name for TLS;
- the PHP process has permission to open outbound stream sockets.

The package does not use PhpRedis, so installing that extension does not fix a
TCP, TLS, ACL, or application configuration problem.

## The connection opens but no events arrive

Check:

1. publisher and endpoint use the same Redis configuration;
2. both use the same `channelPrefix`;
3. the logical publish channel exactly matches the requested channel;
4. the event is published after the subscriber connects;
5. proxy buffering is disabled;
6. a worker or queue process has reloaded current environment configuration.

Redis Pub/Sub does not replay events published before the subscriber was
connected.

The configured `sse.connected` event proves the HTTP response opened; it does
not prove a later application publish used the same Redis instance.

## Named handler does not run

The listener name must equal the event name:

```javascript
live.on('order.updated', handler);
```

An SSE frame with `event: order.updated` does not invoke the browser's default
`onmessage` callback. Register each named event used by the page. The wrapper's
global message handler sees named events that the wrapper is already observing.

## JSON payload is not parsed

Inspect:

```javascript
live.on('message', ({ parsed, raw, parseError }) => {
    console.log({ parsed, raw, parseError });
});
```

The client intentionally keeps the connection alive when one payload is
invalid. Correct the publisher or custom serializer; do not use `eval()` or
insert untrusted raw strings as HTML.

## Events arrive in a burst

An intermediate layer is buffering. Verify:

- Nginx `proxy_buffering off` or `fastcgi_buffering off`;
- gzip disabled for the SSE route;
- CDN caching and transformation disabled;
- `X-Accel-Buffering: no` preserved;
- the PHP runtime flushes output;
- test command uses `curl -N`.

See [Streaming and deployment](deployment.md).

## Browser reconnects every few minutes

This is expected when `maxConnectionSeconds` is finite. The lifecycle normally
looks like:

```text
open → reconnecting → open
```

The server sends a retry hint and native EventSource reconnects. Investigate
only if reconnect never returns to `open`, occurs much faster than configured,
or creates duplicate clients in application code.

## Normal requests freeze while SSE is open

A locking PHP session may still be held by custom authentication code. Resolve
the authenticated user before starting the stream and release the session
lock when no more session writes are required.

Also check PHP-FPM saturation: each SSE connection normally occupies a worker.

## Many users receive 502/503 responses

Inspect:

- PHP-FPM `pm.max_children`;
- application worker memory;
- proxy upstream connection limits;
- Redis subscriber count;
- reconnect bursts after deploys;
- number of EventSource instances per page.

Prefer one stream with multiple authorized channels. For high concurrency,
move long-lived connections to a dedicated SSE gateway while CI4 continues to
publish through Redis.

## Cross-origin cookies are missing

All of these must agree:

- browser client `withCredentials: true`;
- exact origin in `allowedOrigins`;
- server `withCredentials = true`;
- HTTPS;
- cookie `Domain`, `Secure`, and `SameSite` attributes.

`Access-Control-Allow-Origin: *` cannot be used with credentialed requests.
Standard EventSource cannot replace the missing cookie with an arbitrary
authorization header.

If the browser console reports a Content Security Policy violation, add the
SSE origin to the application's `connect-src` directive. CORS and CSP are
independent checks; both must permit a cross-origin stream.
