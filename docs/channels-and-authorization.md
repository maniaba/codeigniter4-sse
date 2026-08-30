# Channels and authorization

Channel names arrive from an untrusted browser. The package validates their
syntax and count, then matches each requested logical channel against the
application's registered channel definitions.

Each channel definition owns one responsibility: it describes the channel name
shape and decides whether the current user may subscribe to a concrete channel
instance. Publishing code can then use the same channel class to build names
without duplicating strings.

## Naming rules

A normal channel:

- is between 1 and 200 bytes;
- consists of dot-separated segments;
- starts each segment with an ASCII letter or digit;
- may otherwise contain letters, digits, `_`, and `-`.

Valid examples:

```text
public.news
users.42.notifications
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
$user = Channel::join('users', $userId, 'notifications');
$tenantDashboard = Channel::join('tenants', $tenantId, 'dashboard');
```

## Secure default

The default registry contains only `PublicChannel`, which authorizes channels
under `public.*`:

```text
public.news      allowed
users.42         denied
orders.918       denied
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

## Channel definitions

Implement `ChannelDefinitionInterface` for every private channel family the
application exposes:

```php
namespace App\Sse\Channels;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;
use Maniaba\CodeIgniterSse\Support\Channel;

final class UserNotificationsChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'users.{userId}.notifications';
    }

    public static function forUser(int|string $userId): Channel
    {
        return Channel::join('users', $userId, 'notifications');
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        $user = $context->user();

        return $user !== null
            && (string) $user->id === $context->param('userId');
    }
}
```

The pattern may contain literal segments, `{parameter}` segments, and a final
`*` wildcard segment. Parameters are available through the authorization
context:

```php
$context->channel();       // users.42.notifications
$context->pattern();       // users.{userId}.notifications
$context->param('userId'); // 42
```

A wildcard segment is intended for broad public or intentionally broad private
families:

```php
final class PublicChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'public.*';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        return true;
    }
}
```

Unknown channels are denied. Every requested channel must match a registered
definition and pass that definition's authorization check. If any channel is
denied, the connection is rejected rather than silently subscribing to a
subset.

When more than one definition could match the same channel, the registry uses
the most specific pattern. For example, `users.{userId}.notifications` wins
over `users.*`.

## Register channels

List channel definitions in `app/Config/Sse.php`:

```php
namespace Config;

use App\Sse\Channels\UserNotificationsChannel;
use App\Sse\UserResolver;
use Maniaba\CodeIgniterSse\Authorization\Channels\PublicChannel;
use Maniaba\CodeIgniterSse\Config\Sse as BaseSse;

final class Sse extends BaseSse
{
    public array $channels = [
        PublicChannel::class,
        UserNotificationsChannel::class,
    ];

    public string $userResolver = UserResolver::class;
}
```

For private streams, also apply an application authentication filter and a
rate/concurrency filter through `Config\Sse::$route['filters']`. Authentication
filters reject unauthenticated requests early; concurrency limits prevent one
user or reconnect loop from consuming an unbounded number of PHP workers.
Neither replaces per-channel authorization.

The same route filters and channel definitions protect direct EventSource
requests and Mercure authorization requests. With Redis, authorization happens
before the PHP stream opens. With Mercure, the resulting subscriber JWT
contains only approved topics. The long-lived Hub connection does not occupy a
PHP worker, but rate limiting still protects token issuance and reconnect
churn.

## Publish notification objects

Event objects should use the channel definition to build the target channel:

```php
namespace App\Sse\Notifications;

use App\Sse\Channels\UserNotificationsChannel;
use Maniaba\CodeIgniterSse\Contracts\PublishableEventInterface;
use Maniaba\CodeIgniterSse\Support\Channel;

final readonly class OrderPaidNotification implements PublishableEventInterface
{
    public function __construct(
        private int $userId,
        private int $orderId,
    ) {
    }

    public function channel(): Channel
    {
        return UserNotificationsChannel::forUser($this->userId);
    }

    public function event(): string
    {
        return 'notification.created';
    }

    public function data(): array
    {
        return [
            'type'    => 'order_paid',
            'orderId' => $this->orderId,
        ];
    }
}
```

Then publish the object directly:

```php
sse()->publish(new OrderPaidNotification($order->user_id, $order->id));
```

The event object knows where the message is published. The channel definition
knows who may subscribe to that channel.

## Dependencies

Channel definitions are usually small and constructor-free. When a definition
needs application services, resolve them inside the authorization method:

```php
namespace App\Sse\Channels;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class TenantDashboardChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'tenants.{tenantId}.dashboard';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        return service('tenantMemberships')->allows(
            $context->user(),
            $context->param('tenantId'),
        );
    }
}
```

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

Enabling them allows Redis glob-style subscription patterns in the browser
request. A pattern can cover a much larger data set than its text suggests, so
a channel definition must explicitly match and approve that pattern. A
registered `users.{userId}` definition does not authorize `users.*`.

Never enable pattern subscriptions for ordinary users merely because the
corresponding exact channel would be allowed.

Redis may report the same publication through overlapping exact and pattern
subscriptions. The adapter suppresses recently seen channel/event ID pairs
using `redis['deduplicationCapacity']`; avoid unnecessary overlap so
correctness does not depend on a bounded deduplication window.

## Browser authentication

Native `EventSource` supports cookies through `withCredentials`, but does not
provide a standard way to set arbitrary authorization headers.

Prefer same-site secure session cookies:

```javascript
import {
    RedisSseAdapter,
    SseClient,
} from '/vendor/codeigniter4-sse/sse-client.js';

new SseClient({
    endpoint: '/sse',
    adapter: new RedisSseAdapter(),
    channels: ['users.42.notifications'],
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
