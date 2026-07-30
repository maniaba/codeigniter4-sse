# CodeIgniter 4.8 migration

CodeIgniter SSE supports CI4 4.7 and newer through one public package API.
Applications do not select a response implementation.

At the time this documentation was written, CodeIgniter 4.8 SSE support was
available on the framework's 4.8 development branch and had not yet been
published as a stable release.

## Native framework API

CodeIgniter 4.8 introduces:

```php
CodeIgniter\HTTP\SSEResponse
```

`SSEResponse` extends the framework's streaming response and is created from a
controller response:

```php
return $this->response->eventStream(
    static function (\CodeIgniter\HTTP\SSEResponse $sse): void {
        $sse->retry(3000);
        $sse->comment('connected');
        $sse->event(
            data: '{"ready":true}',
            event: 'application.ready',
            id: 'event-id',
        );
    },
);
```

The native output provides:

- `event(array|string $data, ?string $event, ?string $id): bool`;
- `comment(string $comment): bool`;
- `retry(int $milliseconds): bool`;
- client disconnect detection;
- incremental write and flush behavior.

Application controllers using this package do not need to call these methods
directly.

## Runtime selection

The package response factory uses feature detection:

```text
response has callable eventStream()
    ├── yes → native CodeIgniter SSEResponse
    └── no  → package legacy streaming response
```

The code does not statically reference `SSEResponse` on CI4 4.7, so the
same installed package works across all supported versions.

The compatibility encoder follows the same SSE field representation as the
native 4.8 response.

## Zero application changes

The following code is identical before and after upgrading CI4:

```php
service('sse')->publish(
    "users.{$userId}",
    'profile.updated',
    ['name' => $user->name],
);
```

The endpoint remains:

```http
GET /sse?channels=users.42
```

The browser remains:

```javascript
const live = new SseClient({
    endpoint: '/sse',
    channels: ['users.42'],
});
```

No application route, publisher, event, channel authorizer, user resolver, or
frontend listener needs to change. The package automatically takes the native
path when `eventStream()` becomes available.

## Upgrade procedure

After CodeIgniter 4.8 is released and the application is ready:

```bash
composer update codeigniter4/framework
composer test
php spark sse:health-check
```

Then run a streaming smoke test:

```bash
curl -N \
    -H 'Accept: text/event-stream' \
    'http://localhost:8080/sse?channels=public.upgrade-test'
```

Verify response headers, connected event, heartbeat, normal event delivery,
disconnect, and browser reconnect.

## Response customization

Keep CORS, security, and route behavior in the package configuration and
application filters rather than constructing the legacy response directly.
Both response implementations are internal integration details.

The native factory controls its own streaming headers. The package applies
cross-origin and security headers to the returned response. Proxy/CDN
`no-transform` behavior remains an infrastructure concern.

## Removing the compatibility path

The legacy response remains necessary while the package supports CodeIgniter
4.7. It can be removed only in a future package major version whose
minimum CodeIgniter requirement is 4.8 or newer.

That future internal cleanup still should not alter the application publisher
or frontend API.
