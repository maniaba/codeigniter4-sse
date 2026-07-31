# Streaming and deployment

An SSE response stays open and flushes frames incrementally. Web servers,
reverse proxies, compression middleware, CDNs, and PHP worker limits must be
configured for that behavior.

This page primarily describes the built-in PHP stream used with Redis. When
the Mercure broker is active, the CodeIgniter route is a short authorization
request and the Hub owns the long-lived response. PHP-FPM stream capacity,
heartbeat, and buffering requirements then apply to the Hub deployment, not
the `/sse` bootstrap route. See [Mercure Hub](mercure.md).

## Response headers

The endpoint uses at least:

```http
Content-Type: text/event-stream
Cache-Control: no-cache, no-transform
Connection: keep-alive
X-Accel-Buffering: no
```

Cross-origin responses additionally receive the configured CORS headers.
`Connection: keep-alive` applies to HTTP/1.x and is not emitted for HTTP/2.
Never cache the endpoint and keep `no-transform` in place so proxies and CDNs
do not buffer or modify the stream.

## Heartbeats and connection lifetime

An idle heartbeat is an SSE comment:

```text
: heartbeat 2026-07-30T19:20:00Z

```

Comments keep intermediate connections active without dispatching a browser
event.

Recommended starting values:

```php
public int $heartbeatInterval = 15;
public int $maxConnectionSeconds = 300;
public int $retryMilliseconds = 3000;
```

Set every proxy idle timeout above the heartbeat interval and above the
expected time needed to reconnect. The server closes a stream after the
maximum lifetime; the browser then reconnects using native EventSource
behavior.

## Nginx with PHP-FPM

For a direct FastCGI route:

```nginx
location = /sse {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param PATH_INFO /sse;
    fastcgi_pass unix:/run/php/php-fpm.sock;

    fastcgi_buffering off;
    fastcgi_request_buffering off;
    fastcgi_read_timeout 360s;

    gzip off;
    add_header X-Accel-Buffering no always;
}
```

Adapt the front-controller parameters and socket path to the existing CI4
site configuration. The important SSE-specific settings are disabled response
buffering and compression plus a sufficient read timeout.

When Nginx proxies another HTTP server:

```nginx
location = /sse {
    proxy_pass http://application;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 360s;

    gzip off;
    add_header X-Accel-Buffering no always;
}
```

Do not apply a global configuration snippet blindly; merge these settings into
the application's established security and front-controller configuration.

## Apache

Disable compression for the SSE content type and ensure proxy/FastCGI timeout
is longer than one connection lifetime. A typical starting point is:

```apache
SetEnvIfNoCase Request_URI "^/sse$" no-gzip=1
SetEnvIfNoCase Request_URI "^/sse$" dont-vary=1

<Location "/sse">
    Header always set Cache-Control "no-cache, no-transform"
    Header always set X-Accel-Buffering "no"
</Location>
```

Module availability and PHP integration differ between Apache deployments.
Verify incremental output with `curl -N`; headers alone do not prove that
FastCGI or proxy buffering is disabled.

## PHP-FPM capacity

One open SSE response normally occupies one PHP-FPM worker. Therefore:

```text
concurrent SSE users ≈ workers held by SSE
```

If `pm.max_children` is 40 and 35 users hold a stream, only about five workers
remain for normal page and API requests. A five-minute connection limit
rotates workers but does not change peak concurrency.

Plan capacity from:

- expected simultaneous users;
- number of EventSource connections per user;
- normal application request traffic;
- worker memory consumption;
- acceptable reconnect bursts during deployments.

Prefer one SSE connection carrying several authorized channels over many
connections per page.

## Session locking

Some PHP session handlers lock a user's session for the duration of a request.
A long-lived SSE request holding that lock can make the same user's normal
requests appear frozen.

Authentication should resolve the user before streaming and release the
session lock as soon as no more session writes are needed. Verify this behavior
with two parallel requests in the deployed environment.

## Redis networking

The Redis connection may stay open for the duration of the HTTP stream:

- use TLS outside a trusted private network;
- use an ACL identity restricted to the application prefix;
- configure infrastructure idle timeouts above the poll/heartbeat behavior;
- keep the Redis PING interval below infrastructure idle timeouts;
- do not expose Redis directly to browsers or the public internet;
- monitor subscriber counts and reconnect failures.

Publishing and subscribing use separate sockets.

## CDN and load balancers

For the SSE path:

- disable caching and response transformation;
- disable or reduce response buffering;
- allow a request duration longer than `maxConnectionSeconds`;
- preserve streaming chunks;
- ensure idle timeout exceeds `heartbeatInterval`;
- avoid retries that duplicate an already-established stream.

Sticky sessions are usually unnecessary because Redis broadcasts to every
application instance. Authentication/session storage may impose separate
requirements.

## Larger deployments

PHP-FPM is suitable for a bounded number of live users. At larger concurrency,
keep the publisher API in CI4 and move long-lived connections to a dedicated
gateway:

```text
CI4 application
    ↓ Redis publish
Redis
    ↓ subscribe
FrankenPHP / RoadRunner / ReactPHP / Swoole gateway
    ↓
Browser
```

The broker and event contracts are intended to preserve application publishing
code when this topology is introduced.

Mercure is the built-in dedicated-gateway option:

```text
CI4 application ── publish ──► Mercure Hub
Browser EventSource ─────────► Mercure Hub
```

It removes long-lived browser streams from PHP without changing
`sse()->publish(...)`.

## Verification

Use unbuffered curl:

```bash
curl -N -v \
    -H 'Accept: text/event-stream' \
    'https://example.com/sse?channels=public.health'
```

Verify:

1. response headers arrive immediately;
2. the connected event arrives without closing the response;
3. heartbeats appear at the configured interval;
4. a published event appears immediately;
5. the stream rotates after maximum lifetime;
6. normal application requests remain responsive under concurrent streams.
