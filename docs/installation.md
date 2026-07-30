# Installation

## Requirements

Before installing the package, verify:

- PHP 8.2 or newer;
- the PHP JSON extension;
- CodeIgniter 4.7 or newer;
- a Redis server reachable from the PHP runtime;
- Composer package discovery enabled in the application.

The Redis adapter speaks RESP2 over PHP stream sockets. No PhpRedis extension
or third-party Redis client is needed.

## Install with Composer

```bash
composer require maniaba/codeigniter4-sse
```

CodeIgniter discovers the package namespace, services, routes, and Spark
commands through Composer.

Publish the application config and browser module:

```bash
php spark sse:install
```

The command creates:

```text
app/Config/Sse.php
public/vendor/codeigniter4-sse/sse-client.js
```

Existing files are skipped. Use `--force` only when they should be replaced,
or `--no-assets` to publish only the PHP config:

```bash
php spark sse:install --no-assets
```

If the application has disabled Composer discovery in `Config\Modules`, enable
it before using the automatic package integration:

```php
public $discoverInComposer = true;
```

Applications using `Config\Modules::$composerPackages['only']` must include the
Composer package name:

```php
public $composerPackages = [
    'only' => [
        'maniaba/codeigniter4-sse',
    ],
];
```

Run the following commands to confirm discovery:

```bash
php spark routes
php spark list
```

The route list should contain the SSE endpoint, and the command list should
contain `sse:health-check`.

## Configure Redis

For local development:

```php
public string $channelPrefix = 'app:sse:';

public array $redis = [
    'host'     => '127.0.0.1',
    'port'     => 6379,
    'database' => 0,
];
```

Then check connectivity:

```bash
redis-cli ping
php spark sse:health-check
```

The health check validates the package configuration and opens a Redis
connection without starting an HTTP stream.

Redis Pub/Sub channels are not isolated by selected database. Always use a
distinct `channelPrefix` for each application and test suite.

## Install the browser client

The installer publishes the source ES module from:

```text
vendor/maniaba/codeigniter4-sse/resources/js/sse-client.js
```

The default public import is:

```javascript
import { SseClient } from '/vendor/codeigniter4-sse/sse-client.js';
```

Alternatively, copy the source into an existing asset pipeline:

```bash
cp vendor/maniaba/codeigniter4-sse/resources/js/sse-client.js \
    public/assets/sse-client.js
```

Then import it:

```javascript
import { SseClient } from '/assets/sse-client.js';
```

Do not serve the entire `vendor/` directory from the web root.

## Verify the HTTP stream

The default authorizer permits `public.*` channels, so a public channel is
useful for the first smoke test:

```bash
curl -N \
    -H 'Accept: text/event-stream' \
    'http://localhost:8080/sse?channels=public.demo'
```

In a second terminal, publish a test event from application code or a small
Spark command:

```php
service('sse')->publish(
    'public.demo',
    'demo.updated',
    ['ready' => true],
);
```

`curl` should receive an SSE frame without waiting for the connection to end.

## Production checklist

Before exposing the endpoint:

1. implement private-channel authorization;
2. configure the authenticated user resolver;
3. apply application authentication plus reconnect rate/concurrency filters to
   private stream routes;
4. use TLS for remote Redis connections;
5. set an application-specific Redis channel prefix;
6. disable proxy and FastCGI buffering for the SSE route;
7. size PHP-FPM for the expected number of concurrent streams;
8. keep finite connection lifetime and heartbeats enabled.

See [Streaming and deployment](deployment.md).
