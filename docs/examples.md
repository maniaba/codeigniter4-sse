# Examples

These examples show the intended application-level API. Controllers, models,
services, and workers publish events without touching Redis directly.

## User notification

Publish from any PHP process:

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

Listen in the browser:

```javascript
import {
    RedisSseAdapter,
    SseClient,
} from '/vendor/codeigniter4-sse/sse-client.js';

const live = new SseClient({
    endpoint: '/sse',
    adapter: new RedisSseAdapter(),
    channels: [`users.${currentUserId}`],
});

live.on('notification.created', ({ data }) => {
    showToast(data.title);
    refreshOrder(data.orderId);
});

live.connect();
```

## Order status

Publish a domain event:

```php
sse()->publish(
    'orders.918',
    'order.updated',
    [
        'orderId' => 918,
        'status'  => 'paid',
    ],
);
```

Update the visible row:

```javascript
live.on('order.updated', ({ data }) => {
    document.querySelector(`[data-order="${data.orderId}"] [data-status]`)
        .textContent = data.status;
});
```

## Dashboard refresh

Publish a tenant dashboard metric:

```php
sse()->publish(
    "tenants.{$tenantId}.dashboard",
    'dashboard.metric.changed',
    [
        'metric' => 'openOrders',
        'value'  => 12,
    ],
);
```

Apply it on the dashboard:

```javascript
live.on('dashboard.metric.changed', ({ data }) => {
    document.querySelector(`[data-metric="${data.metric}"]`)
        .textContent = data.value;
});
```

## Optional UI patch

For generic internal screens, the application may choose to send a small UI
patch event:

```php
sse()->publish(
    'orders.918',
    'ui.patch',
    [
        'target'    => '#order-918-status',
        'operation' => 'text',
        'value'     => 'Paid',
    ],
);
```

This pattern is intentionally not the primary package API because it couples
the backend to page markup.
