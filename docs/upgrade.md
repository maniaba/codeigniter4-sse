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
- `ChannelAuthorizerInterface`
- `UserResolverInterface`
- `EventInterface`
- `Channel`
- `SseEvent`

Redis socket internals and legacy response adapters are implementation
details.

## Event envelope changes

The broker envelope includes a `version` field. Minor releases may add optional
fields, but incompatible event format changes should use a new version number
and retain a deserializer for previous versions where practical.

## Documentation versions

Documentation is published per package version. Use the version selector in
the site header to match the installed Composer package version.

For unreleased work, use the `develop` documentation channel. For installed
releases, use the matching tag version such as `1.0.0`.
