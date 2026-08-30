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

## Upgrade to v2

The v2 channel model replaces the single application-wide
`ChannelAuthorizerInterface` with registered channel definitions.

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

Move each channel family from the old central authorizer into a
`ChannelDefinitionInterface` implementation. A definition provides a pattern
such as `users.{userId}.notifications` and authorizes a matched concrete
channel through `ChannelAuthorizationContext`.

The package no longer ships `PublicChannelAuthorizer`; the equivalent default
is `Authorization\Channels\PublicChannel`.

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
