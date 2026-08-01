# Channels and authorization

Channel names arrive from an untrusted browser. The package validates their
syntax and count, but the application must decide whether the current user is
allowed to subscribe.

## Naming rules

A normal channel:

- is between 1 and 200 bytes;
- consists of dot-separated segments;
- starts each segment with an ASCII letter or digit;
- may otherwise contain letters, digits, `_`, and `-`.

Valid examples:

```text
public.news
users.42
orders.918
tenants.7.dashboard
projects.project_15.activity
roles.support-agent
```

Invalid examples include empty segments, whitespace, slashes, Redis control
characters, and wildcard syntax while pattern subscriptions are disabled.

Server-side PHP can use the value object for validation, or `join()` when it
needs to safely compose a channel from dynamic segments:

```php
use Maniaba\CodeIgniterSse\Support\Channel;

$channel = new Channel('public.news');
$user = Channel::join('users', $userId);
$tenantDashboard = Channel::join('tenants', $tenantId, 'dashboard');
```

## Secure default

`PublicChannelAuthorizer` allows only `public.*`:

```text
public.news  → allowed
users.42     → denied
orders.918   → denied
```

`NullUserResolver` returns `null`. As a result, installing the package does not
silently expose application data.

## User resolver

Implement the small adapter for the authentication package used by the
application:

```php
namespace App\Sse;

use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;

final class UserResolver implements UserResolverInterface
{
    public function resolve(): ?object
    {
        return service('auth')->user();
    }
}
```

The resolver may use CodeIgniter Shield, a session, JWT claims, or an
application-specific auth service. It must return the authenticated subject or
`null`; it must not trust a user ID from the `channels` query parameter.

For CodeIgniter Shield, use the packaged resolver. It uses Shield's `auth`
helper and returns `auth()->user()`:

```php
namespace Config;

use Maniaba\CodeIgniterSse\Authorization\ShieldUserResolver;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $userResolver = ShieldUserResolver::class;
}
```

Resolve authentication before the long-lived stream starts. If the
authentication layer opens a locking PHP session, release the session lock
after resolving the user so the SSE connection does not block the user's
normal HTTP requests.

## Channel authorizer

The authorizer receives the resolved user and one validated logical channel:

```php
namespace App\Sse;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;

final class ChannelAuthorizer implements ChannelAuthorizerInterface
{
    public function authorize(?object $user, string $channel): bool
    {
        if (str_starts_with($channel, 'public.')) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if (preg_match('/^users\.(\d+)$/', $channel, $match) === 1) {
            return (string) $user->id === $match[1];
        }

        return false;
    }
}
```

Every requested channel must pass. If any channel is denied, the connection is
rejected rather than silently subscribing to a subset.

For private streams, also apply an application authentication filter and a
rate/concurrency filter through `Config\Sse::$route['filters']`. Authentication
filters reject unauthenticated requests early; concurrency limits prevent one
user or reconnect loop from consuming an unbounded number of PHP workers.
Neither replaces per-channel authorization.

With Mercure, the same route filters and authorizer protect the short
bootstrap request. The resulting subscriber JWT contains only approved
Mercure topics. The long-lived Hub connection does not occupy a PHP worker,
but rate limiting still protects token issuance and reconnect churn.

For zero-argument implementations, select both classes in the package config:

```php
namespace Config;

use App\Sse\ChannelAuthorizer;
use App\Sse\UserResolver;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $channelAuthorizer = ChannelAuthorizer::class;
    public string $userResolver = UserResolver::class;
}
```

## Dependencies

The config stores class names. Keep these adapters small and constructor-free.
When an adapter needs application services, resolve them inside the adapter
method:

```php
use App\Sse\PolicyChannelAuthorizer;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public string $channelAuthorizer = PolicyChannelAuthorizer::class;
}
```

```php
namespace App\Sse;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;

final class PolicyChannelAuthorizer implements ChannelAuthorizerInterface
{
    public function authorize(?object $user, string $channel): bool
    {
        $orderPolicy = service('orderPolicy');
        $memberships = service('tenantMemberships');

        // Apply application rules here.
        return $orderPolicy->allows($user, $channel)
            || $memberships->allows($user, $channel);
    }
}
```

This keeps package internals out of CodeIgniter's global service registry while
still letting the application integrate its own dependencies.

Keep database queries bounded: authorization runs before the stream starts,
but a request can contain up to `maxChannelsPerConnection` channels.

Batch tenant or project membership checks when possible instead of executing
one unindexed query per channel.

## Pattern subscriptions

Pattern subscriptions are disabled by default:

```php
public array $redis = [
    'allowPatternSubscriptions' => false,
];
```

Enabling them allows glob-style Redis subscription patterns. A pattern can
cover a much larger data set than its text suggests, so the authorizer must
recognize and explicitly approve patterns. Never enable them for ordinary
users merely because the corresponding exact channel would be allowed.

Redis may report the same publication through overlapping exact and pattern
subscriptions. The adapter suppresses recently seen event IDs using
`redis['deduplicationCapacity']`; avoid unnecessary overlap so correctness does
not depend on a bounded deduplication window.

## Browser authentication

Native `EventSource` supports cookies through `withCredentials`, but does not
provide a standard way to set arbitrary authorization headers.

Prefer same-site secure session cookies:

```javascript
new SseClient({
    endpoint: '/sse',
    channels: ['users.42'],
    withCredentials: true,
});
```

For a cross-origin frontend, configure:

- an exact CORS origin allowlist;
- `Access-Control-Allow-Credentials`;
- secure cookie domain and `SameSite` behavior;
- TLS on both origins.

Avoid bearer tokens in the SSE URL. URLs are commonly retained in browser
history, access logs, reverse-proxy logs, and monitoring systems.

The Mercure transport follows this rule by returning authorization in an
HttpOnly cookie. The browser client fetches the CodeIgniter route first and
then opens EventSource directly against the Hub. See
[Mercure Hub](mercure.md).

The SSE endpoint is a read-only `GET` and must not perform state-changing
actions. Keep normal CSRF protection on application writes; do not move a CSRF
or authentication token into the stream URL.

## Payload safety

Authorization protects channel access, not unsafe payload choices. Do not
publish:

- passwords, tokens, or secrets;
- full models when a small projection is sufficient;
- fields a subscriber is not authorized to view;
- tenant identifiers accepted only from client input.

Log event ID, event name, and logical channel when needed. Avoid logging full
payloads by default.
