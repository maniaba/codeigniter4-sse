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

## 2. Define the user notification channel

The browser will request a logical channel such as `users.42.notifications`.
Private channels are denied by default, so the application must register the
channel and decide whether the current user may subscribe.

Implement `ChannelDefinitionInterface`:

```php
<?php

declare(strict_types=1);

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

Register the channel and resolver in `app/Config/Sse.php`:

```php
<?php

declare(strict_types=1);

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

## 3. Publish a notification object

Publish only after the domain operation has succeeded:

```php
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
            'title'   => 'Order paid',
            'orderId' => $this->orderId,
        ];
    }
}

$order->markAsPaid();

sse()->publish(new OrderPaidNotification($order->user_id, $order->id));
```

The publisher builds a versioned envelope and sends it to the prefixed Redis
channel. Application code does not build Redis channel names and does not
write SSE frames.

## 4. Connect from the browser

```javascript
import {
    RedisSseAdapter,
    SseClient,
} from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    adapter: new RedisSseAdapter(),
    channels: [`users.${currentUserId}.notifications`],
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

With the default Redis broker, the browser opens:

```http
GET /sse?channels=users.42.notifications
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
