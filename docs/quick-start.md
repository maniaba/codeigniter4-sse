# Quick start

This example publishes a user notification and updates the page without a full
reload. It uses the default Redis adapter. For the dedicated Hub deployment,
follow [Mercure Hub](mercure.md); publishing and channel policy code remain the
same.

## 1. Configure Redis

Add the development connection to `app/Config/Sse.php`:

```php
public string $channelPrefix = 'example:sse:';

public array $redis = [
    'host'     => '127.0.0.1',
    'port'     => 6379,
    'database' => 0,
];
```

Verify it:

```bash
php spark sse:health-check
```

## 2. Authorize the user channel

The browser will request a logical channel such as `users.42`. Private
channels are denied by default, so the application must decide whether the
current user may subscribe.

Implement `ChannelAuthorizerInterface`:

```php
<?php

declare(strict_types=1);

namespace App\Sse;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;

final class ChannelAuthorizer implements ChannelAuthorizerInterface
{
    public function authorize(?object $user, string $channel): bool
    {
        if (
            $user !== null
            && preg_match('/^users\.(\d+)$/', $channel, $matches) === 1
        ) {
            return (string) $user->id === $matches[1];
        }

        return str_starts_with($channel, 'public.');
    }
}
```

Implement `UserResolverInterface` for the application's authentication layer:

```php
<?php

declare(strict_types=1);

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

Select these implementations in `app/Config/Sse.php`:

```php
<?php

declare(strict_types=1);

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

## 3. Publish an event

Publish only after the domain operation has succeeded:

```php
$order->markAsPaid();

sse()->publish(
    "users.{$order->user_id}",
    'notification.created',
    [
        'title'   => 'Order paid',
        'orderId' => $order->id,
    ],
);
```

The publisher builds a versioned envelope and sends it to the prefixed Redis
channel. Application code does not build Redis channel names and does not
write SSE frames.

If the application already has an event object, implement
`PublishableEventInterface` and call `sse()->publish($object)`. The object
provides the channel, event name or event object, and payload.

## 4. Connect from the browser

```javascript
import { SseClient } from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    channels: [`users.${currentUserId}`],
    withCredentials: true,
});

live.on('notification.created', ({ data }) => {
    showToast(data.title);
    refreshOrder(data.orderId);
});

live.on('status', ({ status }) => {
    document.querySelector('[data-live-status]').textContent = status;
});

live.connect();
```

The client first resolves the server-selected stream:

```http
GET /sse?channels=users.42
Accept: application/json
```

With the default Redis broker, it then opens:

```http
GET /sse?channels=users.42
Accept: text/event-stream
```

Session cookies are sent when allowed by the browser and CORS configuration.
Standard browser `EventSource` does not support arbitrary `Authorization`
headers.

## 5. Close when appropriate

Most full-page applications can let navigation close the connection. A
single-page application should close streams owned by an unmounted view:

```javascript
live.close();
```

Calling `connect()` later opens a new `EventSource` with the existing
listeners.

## Prefer semantic events

Send domain facts:

```php
sse()->publish(
    "orders.{$order->id}",
    'order.updated',
    [
        'orderId' => $order->id,
        'status'  => $order->status,
    ],
);
```

Let the frontend choose the DOM update:

```javascript
live.on('order.updated', ({ data }) => {
    const target = document.querySelector(
        `[data-order-status="${data.orderId}"]`,
    );

    if (target !== null) {
        target.textContent = data.status;
    }
});
```

Sending HTML selectors and fragments as the primary event format couples the
backend to one page structure and is harder to reuse safely.
