# Upgrade policy

The package is designed so most applications depend on the high-level service
and contracts rather than concrete Redis or HTTP classes.

## Supported platform

The current package line targets:

- PHP 8.2 or newer;
- CodeIgniter 4.7 or newer;
- Redis Pub/Sub for live delivery without replay;
- Mercure 0.x Hub protocol for direct Hub streaming and retained-history
  replay when configured.

## Stable API surface

These APIs should be treated as the main compatibility surface:

- `sse()->publish()`
- `PublisherInterface`
- `SubscriberInterface`
- `ChannelDefinitionInterface`
- `UserResolverInterface`
- `EventInterface`
- `Channel`
- `SseEvent`

## Upgrade from v1.0.0-rc2 to v1.0.0-rc3

The `v1.0.0-rc3` channel model replaces the single application-wide
`ChannelAuthorizerInterface` from `v1.0.0-rc2` with registered channel
definitions.

Update the package:

```bash
composer require maniaba/codeigniter4-sse:1.0.0-rc3
php spark sse:install
```

Then update `app/Config/Sse.php`.

Before:

```php
public string $channelAuthorizer = \App\Sse\ChannelAuthorizer::class;
```

After:

```php
public array $channels = [
    \Maniaba\CodeIgniterSse\Authorization\Channels\PublicChannel::class,
    \App\Sse\Channels\UserNotificationsChannel::class,
];
```

Keep `public string $userResolver` unchanged unless the application is also
changing authentication.

Move each channel family from the old central authorizer into a small
`ChannelDefinitionInterface` implementation:

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

An old authorizer branch like this:

```php
if (preg_match('/^users\.(\d+)$/', $channel, $matches) === 1) {
    return $user !== null && (string) $user->id === $matches[1];
}
```

becomes a registered channel definition with a pattern such as
`users.{userId}` or, for notification-specific streams,
`users.{userId}.notifications`.

The package no longer ships `PublicChannelAuthorizer`; the equivalent default
is `Authorization\Channels\PublicChannel`.

Event object publishing does not need to change. Existing classes that
implement `PublishableEventInterface` can keep calling `channel()`, `event()`,
and `data()`. Prefer returning channels through the new channel definitions:

```php
public function channel(): Channel
{
    return UserNotificationsChannel::forUser($this->userId);
}
```

Redis socket internals and legacy response adapters are implementation
details.

## Event envelope changes

The broker envelope includes a `version` field. Minor releases may add optional
fields, but incompatible event format changes should use a new version number
and retain a deserializer for previous versions where practical.

## Documentation versions

Documentation is published per package version. Use the version selector in
the site header to match the installed Composer package version.

Before the first stable release, documentation deploys without a package
version publish the `develop` channel and move the `latest` alias/default
redirect to `develop`.

After any stable docs version exists, `develop` continues to publish as its own
channel but never moves `latest` again. Tagged stable releases publish their
tag version, such as `1.0.0`, and move `latest` to that release. Prereleases
are published without changing `latest`.
