# Mercure Hub

The Mercure adapter keeps the same application publishing API while moving
long-lived browser connections out of PHP:

```text
CodeIgniter application
    ├─ sse()->publish() ── HTTP POST ──► Mercure Hub
    └─ GET /sse?channels=... ─────────► authorize channels and set JWT cookie

Browser
    ├─ fetch GET /sse?channels=...
    └─ EventSource ───────────────────► Mercure Hub
```

The `/sse` CodeIgniter route is a short authorization request in this mode. It
does not emit an event stream and does not reserve a PHP worker.
The Hub owns heartbeats, reconnects, history, and the live SSE response.

Mercure is the recommended built-in broker for larger environments and
applications with many concurrent users. Redis is simpler and works well when
PHP worker capacity is intentionally sized for live streams. Mercure is a
better fit when the number of open browser connections can grow beyond that
comfort zone, because idle SSE connections no longer consume PHP-FPM workers.

The adapter targets the stable Mercure 0.x protocol used by Mercure 0.24.2:
`topic` subscription parameters, `mercure.publish` and `mercure.subscribe` JWT
claims, and the `mercureAuthorization` cookie.

The Hub is an external deployment and is not bundled into this MIT package.
Review the Mercure Hub's AGPL/commercial licensing options for the intended
deployment.

## Start a development Hub

This Docker Compose service uses separate publisher and subscriber keys. Use
different long random values in a real environment:

```yaml
services:
  mercure:
    image: dunglas/mercure:v0.24.2
    restart: unless-stopped
    environment:
      SERVER_NAME: ':80'
      MERCURE_PUBLISHER_JWT_KEY: 'replace-with-a-long-publisher-secret'
      MERCURE_SUBSCRIBER_JWT_KEY: 'replace-with-a-long-subscriber-secret'
      MERCURE_EXTRA_DIRECTIVES: |
        cors_origins http://localhost:8080
    ports:
      - '3000:80'
    volumes:
      - mercure_data:/data
      - mercure_config:/config

volumes:
  mercure_data:
  mercure_config:
```

The Hub and package keys must match. Do not commit production keys.

## Configure CodeIgniter

Select the built-in broker and configure the internal and browser-facing Hub
URLs:

```php
<?php

declare(strict_types=1);

namespace Config;

use App\Sse\ChannelAuthorizer;
use Maniaba\CodeIgniterSse\Authorization\ShieldUserResolver;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $broker = 'mercure';

    public string $channelAuthorizer = ChannelAuthorizer::class;
    public string $userResolver = ShieldUserResolver::class;

    public array $mercure = [
        // URL used by PHP. In Docker this can use the service name.
        'hubUrl'       => 'http://mercure/.well-known/mercure',

        // URL returned to browsers.
        'publicHubUrl' => 'https://app.example.com/.well-known/mercure',

        // Produces topics such as urn:storefront:sse:users.42.
        'topicPrefix'  => 'urn:storefront:sse:',

        'private'              => true,
        'authorizeSubscribers' => true,

        'publisherKey'  => null,
        'subscriberKey' => null,
        // Defaults to topicPrefix . '{channel}'.
        'publisherTopicSelectors' => null,
        'allowGlobalPublisherSelector' => false,

        'cookie' => [
            'name'     => 'mercureAuthorization',
            'domain'   => '',
            'path'     => '/.well-known/mercure',
            'secure'   => true,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ],
    ];
}
```

Supply secrets through `.env`:

```dotenv
sse.broker = mercure
sse.mercure.publisherKey = replace-with-the-hub-publisher-key
sse.mercure.subscriberKey = replace-with-the-hub-subscriber-key
```

For a PHP process running outside Docker, `hubUrl` can be
`http://127.0.0.1:3000/.well-known/mercure`. For local plain HTTP, set
`sse.mercure.cookie.secure = false`. Keep secure cookies enabled in production.

`publicHubUrl` should normally be exposed through the same site as the
application. A same-origin reverse proxy avoids most CORS and cookie-domain
problems:

```text
https://app.example.com/.well-known/mercure → http://mercure/.well-known/mercure
```

At minimum, production Mercure configuration should cover:

- a Hub instance reachable from PHP through `hubUrl`;
- a browser-facing Hub URL in `publicHubUrl`;
- separate long random publisher and subscriber keys;
- matching keys in the Hub environment and CodeIgniter `.env`;
- `private = true` and `authorizeSubscribers = true` for user or tenant data;
- HTTPS and secure cookies outside local development;
- exact CORS origins when the frontend and Hub are cross-origin;
- a reverse proxy or load balancer that supports long-lived Hub responses.

## Publish

No application publishing code changes:

```php
sse()->publish(
    "users.{$userId}",
    'notification.created',
    [
        'title'   => 'Order paid',
        'orderId' => 918,
    ],
);
```

`PublishableEventInterface` and direct `EventInterface` publishing work in the
same way as with Redis:

```php
sse()->publish($event);
```

The adapter:

1. maps the logical channel to a Mercure topic;
2. serializes the normal versioned package envelope;
3. sends the event ID as Mercure `id`;
4. sends the event name as Mercure `type`;
5. sends `private=on` when private updates are enabled;
6. authenticates the POST with a publisher JWT.

The Debug Toolbar collector records Mercure publishes through the same
publisher decorator used for every broker.

## Browser client

Use `MercureSseAdapter` when the server broker is configured for Mercure:

```javascript
import {
    MercureSseAdapter,
    SseClient,
} from '@maniaba/codeigniter4-sse-browser';

const live = new SseClient({
    endpoint: '/sse',
    adapter: new MercureSseAdapter(),
    channels: [`users.${currentUserId}`],
    withCredentials: true,
});

live.on('notification.created', ({ data }) => {
    showToast(data.title);
});

live.connect();
```

The client first fetches:

```http
GET /sse?channels=users.42
Accept: application/json
```

CodeIgniter validates and authorizes every channel, sets an HttpOnly
`mercureAuthorization` cookie, and returns:

```json
{
  "hub": "https://app.example.com/.well-known/mercure",
  "topics": ["urn:storefront:sse:users.42"],
  "expiresAt": 1785520800
}
```

The JWT is never exposed to JavaScript or placed in the EventSource URL. The
client opens the Hub URL with one repeated `topic` parameter per authorized
channel. It refreshes authorization and reconnects shortly before the token
expires. Calling `subscribe()`, `unsubscribe()`, or `setChannels()` obtains a
new token restricted to the new topic list.

Use one `SseClient` per page and combine its channels. Mercure authorization is
stored in one cookie, so separate clients on the same cookie scope can replace
each other's exact-topic authorization during authorization or reconnect.

## Authorization rules

Mercure authorization adds a second enforcement layer; it does not replace the
application policy:

1. `UserResolverInterface` resolves the authenticated application user.
2. `ChannelAuthorizerInterface` approves every requested logical channel.
3. The package maps only approved channels to topics.
4. The subscriber JWT contains only those exact topics in
   `mercure.subscribe`.
5. The Hub delivers private updates only when a JWT selector matches.

Keep `private = true` for user, tenant, order, and project data. Setting
`private = false` publishes updates publicly at the Hub even if CodeIgniter
protected the authorization route.

For a completely public Hub, both of these must be intentional:

```php
public array $mercure = [
    'private'              => false,
    'authorizeSubscribers' => false,
    // Publisher credentials are still required by Mercure.
];
```

The Hub must also enable its `anonymous` directive. Public mode is not suitable
for channels containing user-specific data.

## Cookie, CORS, and TLS

Native EventSource cannot attach an arbitrary Authorization header. The
adapter therefore uses the Mercure cookie flow recommended for browsers.

For a cross-origin Hub:

- add the exact application origin to the Hub `cors_origins` directive;
- keep `withCredentials: true`;
- never use `cors_origins *` with credentials;
- choose a cookie domain shared by the application and Hub;
- use `SameSite=None` only with `Secure=true`;
- serve both endpoints over HTTPS;
- allow the Hub origin in Content Security Policy `connect-src`.

The CodeIgniter authorization route also rejects
`Sec-Fetch-Site: cross-site` bootstrap requests by default before issuing the
subscriber cookie. Set `rejectCrossSiteBootstrap = false` only for trusted
legacy or non-browser clients that cannot send Fetch Metadata headers.

An application on `app.example.com` can set `domain = '.example.com'` for a
Hub on `hub.example.com`. An application cannot set a cookie for an unrelated
site. Use a same-origin reverse proxy in that case.

## Replay and event IDs

Mercure can retain updates in its configured transport. Native EventSource
sends `Last-Event-ID` when reconnecting, and the Hub can replay retained
updates. The package supplies each event's ID to the Hub, so no browser
`sessionStorage` deduplication layer is needed.

Replay depends on Hub history configuration and retention. It is not a queue,
an acknowledgement mechanism, or a replacement for authoritative database
state. A client disconnected longer than the retained history must refetch
current state over normal HTTP.

## Health checks

```bash
php spark sse:health-check
```

For Mercure, this validates package configuration and confirms that `ext-curl`
is available. The Hub exposes transport-aware readiness at
`GET /mercure/health/ready` on Caddy's admin API, port 2019 by default. Keep
that admin API private and run the readiness probe inside the Hub container or
pod. Do not expose the entire Caddy admin API publicly.

## Configuration reference

| Key | Default | Purpose |
|---|---|---|
| `hubUrl` | `http://127.0.0.1:3000/.well-known/mercure` | Server-side publish URL. |
| `publicHubUrl` | same local URL | Browser-facing subscription URL. |
| `topicPrefix` | `urn:codeigniter4-sse:` | Literal absolute IRI prefix added to logical channels. Wildcard and URI-template characters are rejected. |
| `private` | `true` | Mark published updates as private. |
| `authorizeSubscribers` | `true` | Issue a topic-restricted subscriber JWT cookie. |
| `publisherJwt` | `null` | Optional pre-generated publisher JWT. |
| `publisherKey` | `null` | HMAC key used to generate publisher JWTs. |
| `subscriberKey` | `null` | HMAC key used to generate subscriber JWTs. |
| `publisherAlgorithm` | `HS256` | Publisher JWT algorithm: HS256, HS384, or HS512. |
| `subscriberAlgorithm` | `HS256` | Subscriber JWT algorithm: HS256, HS384, or HS512. |
| `publisherTokenTtl` | `300` | Generated publisher token lifetime in seconds. |
| `subscriberTokenTtl` | `3600` | Browser token lifetime in seconds. |
| `publisherTopicSelectors` | `null` | Topics the generated publisher JWT may publish. `null` becomes `topicPrefix . '{channel}'`. |
| `allowGlobalPublisherSelector` | `false` | Allows `publisherTopicSelectors` to contain `*`. Enable only for an intentionally global publisher. |
| `connectTimeout` | `2.5` | Hub connection timeout in seconds. |
| `timeout` | `5.0` | Complete publish request timeout in seconds. |
| `verifyTls` | `true` | TLS verification flag or CA bundle path. |
| `maxPayloadBytes` | `1048576` | Maximum serialized event size. |
| `cookie` | secure Mercure defaults | Subscriber cookie attributes. |

The built-in JWT issuer supports HMAC algorithms and requires HMAC signing keys
to be at least 32 bytes. Generated JWTs include `iss`, `aud`, `sub`, `jti`,
`iat`, and `exp` claims plus the Mercure scope. `publisherJwt` can contain a
token issued by an external system. The package does not cryptographically
verify pre-generated publisher tokens, but it rejects malformed JWTs, unsupported
`alg` values, missing or expired `exp` claims, missing `mercure.publish` rights,
and publish selectors outside `topicPrefix` unless the global `*` selector was
explicitly enabled. Dynamic subscriber authorization still requires the
configured HMAC subscriber key. Applications using an external OAuth/JWKS issuer
should replace the authorization controller rather than exposing signing keys to
the browser.

The adapter currently maps exact logical channels. Redis glob patterns are not
accepted by the Mercure transport; pattern selectors remain a Redis adapter
option.
