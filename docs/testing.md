# Testing

The package separates fast deterministic tests from checks that need a real
Redis process or browser.

## Package checks

Install development dependencies, then run:

```bash
composer validate
composer analyze
composer test
composer test-browser
```

Or run the combined script:

```bash
composer ci
```

The combined command requires Node.js 18 or newer for the framework-independent
browser client test. `npm test` is an equivalent direct command.

PHPUnit boots CodeIgniter's official test environment. The test suite should
remain independent of an application checkout.

## Unit test responsibilities

Unit tests cover:

- event creation, IDs, names, and JSON serialization;
- broker envelope versioning and invalid payloads;
- logical channel syntax and helper constructors;
- channel request limits and authorization;
- SSE frame encoding, multiline data, comments, and retry values;
- heartbeat and maximum-lifetime decisions;
- native response feature detection and legacy response output;
- Redis RESP parsing and command failures;
- browser-client status, listener, and parsing behavior.

Use fixed clocks and event ID generators where deterministic output matters.

## Broker contract tests

Every broker implementation should satisfy the same public behavior:

```text
Publisher contract
Subscriber contract
Pattern subscription contract, when supported
```

`InMemoryBroker` is useful for one-process unit tests:

```php
use Maniaba\CodeIgniterSse\Broker\InMemory\InMemoryBroker;

$broker = new InMemoryBroker();
```

It is not a Redis substitute for an end-to-end HTTP test. Separate PHP
requests do not share its memory.

`NullBroker` is useful when a test must assert that publishing is harmless but
does not need delivery.

## Redis integration tests

Run the repository's isolated Redis service:

```bash
docker compose up -d redis
```

It exposes Redis on host port `16379`. Enable the live integration test:

```bash
SSE_REDIS_INTEGRATION=1 composer test
```

Run the Mercure publisher/subscriber integration test against the development
Hub:

```bash
docker compose up -d mercure
SSE_MERCURE_INTEGRATION=1 composer test -- --group integration
```

The test issues a private topic JWT, opens a real Hub subscription, publishes
through `MercurePublisher`, and verifies the versioned event envelope received
over SSE.

For an externally managed test Redis, override the host and port:

```bash
SSE_REDIS_INTEGRATION=1 \
SSE_REDIS_HOST=127.0.0.1 \
SSE_REDIS_PORT=16379 \
composer test
```

The live test also requires `pcntl_fork()` and Unix stream socket pairs; it is
skipped when those functions are unavailable.

The bundled integration test uses an isolated `integration:sse:` channel
prefix; do not run multiple copies of it concurrently against the same Redis
instance. Custom parallel suites should generate a unique prefix per process.
Redis Pub/Sub is not isolated by numbered Redis databases. Tests must never
issue a broad Redis flush against a shared environment.

Integration scenarios should include:

1. publisher sends and subscriber receives the same versioned event;
2. two subscribers receive one broadcast;
3. publisher and subscriber use distinct connections;
4. ACL authentication and database selection;
5. read timeout returns control for heartbeat processing;
6. disconnect stops subscription;
7. reconnect restores a subscription;
8. overlapping patterns do not surprise the application contract;
9. malformed RESP and Redis error frames fail predictably.

From a host CodeIgniter application, run the operational check after setting
the matching `channelPrefix` and `redis` config array values:

```bash
php spark sse:health-check
```

The `SSE_REDIS_HOST` and `SSE_REDIS_PORT` variables above belong only to this
repository's integration test and are not read by the Spark command.

## HTTP feature tests

Feature tests should verify:

- `GET /sse` route discovery;
- JSON stream bootstrap and required `Accept: text/event-stream` for direct streams;
- missing, invalid, duplicate, and excessive channels;
- default `public.*` access;
- rejection of unauthorized private channels;
- CORS allowlist and credentials;
- connected event, retry field, event frames, and heartbeat comments;
- finite stream lifetime;
- session lock release in the legacy response;
- native and compatibility response selection.

Use recording implementations of `SubscriberInterface` and
`SseOutputInterface` so feature tests do not block.

## Browser client tests

`SseClient` accepts `fetchFactory` and `eventSourceFactory` specifically so
tests can supply small deterministic fakes:

```javascript
const source = new FakeEventSource();

const live = new SseClient({
    endpoint: 'https://example.test/sse',
    channels: ['public.test'],
    fetchFactory: async () => ({
        ok: true,
        status: 200,
        json: async () => ({ url: null, expiresAt: null }),
    }),
    eventSourceFactory: (url, options) => {
        expect(url).toContain('channels=public.test');
        expect(options.withCredentials).toBe(true);

        return source;
    },
});
```

Test at least:

- query-string construction;
- named and global dispatch;
- handler removal;
- valid and invalid JSON;
- `connecting → open → reconnecting → open`;
- manual close;
- unsupported EventSource fallback;
- fallback errors not breaking other handlers.

## End-to-end test

A browser E2E scenario should:

```text
open authenticated page
→ establish EventSource
→ publish from backend
→ verify DOM update
→ terminate the stream
→ observe native reconnect
→ publish another event
→ verify the second update
```

Also verify that a different authenticated user cannot subscribe to the first
user's channel.

## Manual smoke test

Use `curl -N` so output is not buffered:

```bash
curl -N \
    -H 'Accept: text/event-stream' \
    'http://localhost:8080/sse?channels=public.test'
```

Expected initial fields include the reconnect hint and, when enabled, the
`sse.connected` event. Publish another event and verify that it appears before
the HTTP response ends.
