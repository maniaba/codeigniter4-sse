# Events

CodeIgniter SSE sends semantic events. The backend publishes a named event and
the browser decides how the page should react.

## Publish an event

The high-level service accepts a logical channel, an event name, and a payload:

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

Domain code can also publish one object that knows its channel, event name, and
payload:

```php
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
        return Channel::join('users', $this->userId);
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

sse()->publish(new OrderPaidNotification($userId, 918));
```

Advanced code can publish a concrete event object:

```php
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Support\Channel;

service('ssePublisher')->publish(
    (string) Channel::join('orders', 918),
    new SseEvent(
        'order.updated',
        ['orderId' => 918, 'status' => 'paid'],
    ),
);
```

## Event envelope

Every broker message uses a versioned JSON envelope:

```json
{
  "id": "01985f0d-8f00-7a11-8b22-0123456789ab",
  "event": "order.updated",
  "channel": "orders.918",
  "data": {
    "orderId": 918,
    "status": "paid"
  },
  "occurredAt": "2026-07-30T19:10:00+00:00",
  "version": 1
}
```

The browser receives the envelope in `message.data`.

```javascript
live.on('order.updated', ({ data }) => {
    updateOrderRow(data.orderId, data.status);
});
```

Redis serializes this envelope into Pub/Sub and the PHP stream encoder emits
its ID and event name. Mercure sends the same JSON as update `data`, the event
ID as Mercure `id`, and the event name as Mercure `type`. Browser handlers
therefore receive the same normalized message through either adapter.

Redis Pub/Sub does not retain the ID for replay. A Mercure Hub with history
enabled can use it during native `Last-Event-ID` recovery.

## Naming

Use domain event names as the default:

```text
notification.created
order.updated
user.balance.updated
project.activity.created
dashboard.metric.changed
```

Reserve generic UI events such as `ui.patch` for admin screens or internal
tools where the backend intentionally controls a small DOM update.

## Compatibility

The envelope contains `version` from the first package version. Future payload
changes should create a new envelope version or add optional fields in a
backward-compatible way.
