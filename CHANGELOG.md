# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Replaced the single `ChannelAuthorizerInterface` authorization hook with
  registered `ChannelDefinitionInterface` classes.
- Replaced `Config\Sse::$channelAuthorizer` with `Config\Sse::$channels`.
- Replaced `PublicChannelAuthorizer` with the registered `PublicChannel`
  definition.
- Updated authorization documentation and quick-start examples to use channel
  definitions plus `sse()->publish(new Notification(...))` event objects.

### Added

- Added channel pattern matching with literal segments, `{parameter}` segments,
  and final `*` wildcard segments.
- Added `ChannelAuthorizationContext`, `ChannelMatch`, `ChannelPattern`, and
  `ChannelRegistry` for registered channel authorization.

### Removed

- Removed `ChannelAuthorizerInterface`.
- Removed `PublicChannelAuthorizer`.

## [v1.0.0-rc2] - 2026-08-01

Second release candidate focused on Mercure hardening, JWT validation, safer
request parsing, and test cleanup before the initial stable `1.0.0` release.

### Added

- Added Mercure JWT standard claims for generated publisher and subscriber
  tokens: `iss`, `aud`, `sub`, and unique `jti`.
- Added `MercureJwtCodec` for compact JWT splitting, base64url encoding,
  unsigned JWT creation, and JSON header/payload decoding.
- Added structural validation for configured `publisherJwt` values:
    - compact JWT format with three segments;
    - supported HMAC `alg`;
    - required and non-expired `exp`;
    - required `mercure.publish` rights;
    - publish selectors constrained to `topicPrefix` unless the global selector
      is explicitly enabled.
- Added `allowGlobalPublisherSelector` Mercure option. The global publisher
  selector `*` is now allowed only when explicitly opted in.
- Added `rejectCrossSiteBootstrap` option. Mercure authorization bootstrap
  requests with `Sec-Fetch-Site: cross-site` are rejected by default before a
  subscriber cookie is issued.

### Changed

- Changed the default Mercure publisher selector from global `*` to a scoped
  selector derived from `topicPrefix`: `topicPrefix . '{channel}'`.
- Refactored Mercure JWT encoding/decoding into a dedicated codec used by both
  generated JWT creation and configured publisher JWT validation.
- Refactored Mercure route tests to remove duplicated setup and share the
  bootstrap response helper.

### Security

- Enforced a minimum 32-byte Mercure JWT HMAC signing key.
- Rejected Mercure `topicPrefix` values containing wildcard or URI-template
  characters such as `{`, `}`, `*`, `?`, `[`, and `]`.
- Bounded the raw `channels` query input before parsing so oversized requests
  are rejected before channel splitting/deduplication work.
- Kept unauthorized channel responses generic while logging server-side audit
  metadata for denied channel authorization attempts.
- Added Fetch Metadata protection for Mercure subscriber authorization cookie
  bootstrap requests.

### Tests

- Added and updated tests for Mercure JWT key length, standard JWT claims, JWT
  codec behavior, configured publisher JWT validation, scoped publisher
  selectors, `topicPrefix` safety, raw `channels` limits, and cross-site
  bootstrap rejection.

## [v1.0.0-rc] - 2026-08-01

First release candidate for CodeIgniter SSE. This version establishes the
public package API, broker contracts, browser client, Redis transport, Mercure
transport, security model, operational tooling, and documentation set intended
for the initial stable `1.0.0` release.

### Release highlights

- Provides a framework-native CodeIgniter 4 API for publishing Server-Sent
  Events through `sse()->publish(...)`.
- Supports two production transports out of the box:
    - Redis Pub/Sub for direct PHP SSE streaming.
    - Mercure Hub for high-concurrency deployments where long-lived browser
      connections should be handled outside PHP-FPM.
- Keeps application publishing code independent of the selected transport.
  Applications can start with Redis and switch to Mercure without rewriting
  domain publishing calls.
- Ships a dependency-free browser ES module with broker-specific adapters,
  named event handlers, lifecycle status events, JSON parsing, channel
  management, and Mercure authorization refresh.
- Adds a secure default authorization model: only `public.*` channels are
  allowed until the application provides explicit user and channel policy
  implementations.

### Added

#### Core package

- Added `Maniaba\CodeIgniterSse\Sse` as the main application-facing service.
- Added `sse()` helper for concise publishing from controllers, services,
  listeners, Spark commands, and queue workers.
- Added semantic event publishing by channel, event name, payload, and optional
  event ID.
- Added `EventInterface`, `PublishableEventInterface`, and
  `EventFactory` support for applications that prefer event objects over
  direct channel/name/payload calls.
- Added versioned JSON event envelopes so brokers and browser clients receive a
  stable payload shape.
- Added deterministic event IDs through `EventIdGeneratorInterface` and the
  built-in UUIDv7 generator.
- Added strict event validation for names, payloads, IDs, and serialized
  payload size.

#### HTTP streaming and routing

- Added automatic CodeIgniter route registration for `GET /sse`.
- Added configurable route path, route name, controller, method, filters, and
  route options through `Config\Sse::$route`.
- Added direct SSE response handling for Redis/local-style brokers.
- Added response compatibility support for current and legacy CodeIgniter
  response behavior.
- Added SSE frame encoding for event names, IDs, retry hints, comments,
  multiline data, and JSON payloads.
- Added heartbeat comments while streams are idle.
- Added finite stream lifetimes through `maxConnectionSeconds` so PHP workers
  can rotate and deployments can drain old streams.
- Added initial `sse.connected` system event with the authorized channel list.
- Added `sse.error` emission path for recoverable stream subscription failures.
- Added strict `Accept: text/event-stream` handling for direct PHP streams.
- Added JSON preflight handling for Mercure authorization requests.

#### Broker architecture

- Added broker contracts for publishing, subscribing, health checks,
  subscription endpoints, broker adapters, and broker adapter factories.
- Added configurable broker map through `Config\Sse::$brokers`.
- Added built-in broker keys:
    - `redis`
    - `mercure`
    - `memory`
    - `null`
- Added custom broker extension points so applications can provide their own
  adapters without changing the package's public publishing API.
- Added in-memory broker for isolated one-process tests.
- Added null broker for harmless publishing in tests or disabled delivery
  scenarios.

#### Redis transport

- Added Redis Pub/Sub broker adapter as the default transport.
- Added internal RESP2 stream-socket Redis client; PhpRedis and Predis are not
  required.
- Added separate Redis publisher and subscriber sockets.
- Added Redis connection options for scheme, host, port, ACL username/password,
  database selection, connect/read timeouts, polling, PING interval, reconnect
  attempts, reconnect delay, client name, and PHP stream context.
- Added TLS support through PHP stream context options.
- Added application-level `channelPrefix` isolation for physical Redis Pub/Sub
  channels.
- Added explicit documentation that Redis Pub/Sub is global across numbered
  Redis databases and must be isolated by prefix.
- Added Redis health checking through `php spark sse:health-check`.
- Added Redis subscriber health PINGs for half-open subscribed sockets.
- Added bounded reconnect behavior for publisher and subscriber transports.
- Added payload and RESP parser safety limits:
    - maximum payload bytes;
    - maximum RESP array elements;
    - maximum RESP nesting depth.
- Added optional Redis pattern subscriptions with secure disabled-by-default
  configuration.
- Added channel/event ID deduplication for overlapping exact and pattern
  subscriptions.
- Added live Redis Pub/Sub integration tests using the repository Docker
  service.

#### Mercure transport

- Added Mercure 0.x Hub broker adapter.
- Added Mercure publishing via HTTP POST while keeping the same
  `sse()->publish(...)` API used by Redis.
- Added exact logical-channel to Mercure-topic mapping through configurable
  `topicPrefix`.
- Added private Mercure updates by default.
- Added publisher JWT generation with HMAC algorithms.
- Added optional externally supplied publisher JWT support.
- Added subscriber JWT generation for browser authorization.
- Added topic-scoped `mercure.subscribe` claims so browsers receive only
  approved topics.
- Added topic-scoped `mercure.publish` support for publisher credentials.
- Added configurable publisher and subscriber token TTLs.
- Added configurable Hub URLs:
    - `hubUrl` for PHP/server-side publishing;
    - `publicHubUrl` for browser-facing EventSource connections.
- Added secure Mercure cookie configuration for the HttpOnly subscriber token.
- Added local-development support for non-secure Mercure cookies.
- Added Mercure publish payload size limits.
- Added Mercure publish error handling with Hub status/body reporting.
- Added Mercure health-check validation for configuration and `ext-curl`.
- Added live Mercure publisher/subscriber integration test against the
  development Hub.
- Documented Mercure as the recommended built-in transport for larger
  environments and applications with many concurrent users.

#### Browser client

- Added dependency-free browser ES module under `resources/js`.
- Added npm package entry for `@maniaba/codeigniter4-sse-browser`.
- Added TypeScript declaration files for the browser client and adapters.
- Added `SseClient` wrapper around native `EventSource`.
- Added frontend adapters for:
    - Redis;
    - Mercure;
    - direct EventSource usage;
    - local broker semantics;
    - in-memory broker semantics.
- Added named event listeners with `on()` and `off()`.
- Added global message handling.
- Added lifecycle status notifications for connection state.
- Added safe JSON parsing with raw payload and parse-error access.
- Added channel management through `subscribe()`, `unsubscribe()`, and
  `setChannels()`.
- Added automatic EventSource reconnect behavior using server retry hints.
- Added explicit `connect()` and `close()` lifecycle methods.
- Added query parameter support for application metadata.
- Added credential configuration for cookie-authenticated EventSource
  connections.
- Added Mercure authorization bootstrap: the client first calls the CodeIgniter
  route for JSON authorization, then opens EventSource directly against the Hub.
- Added Mercure token refresh before subscriber authorization expires.
- Added Mercure stale authorization cancellation when channel lists change.
- Added test seams through custom `eventSourceFactory` and adapter fetch
  factories.

#### Channel security and authorization

- Added strict logical channel validation:
    - 1 to 200 bytes;
    - dot-separated segments;
    - ASCII alphanumeric segment starts;
    - `_` and `-` inside segments;
    - no whitespace, slashes, empty segments, or control characters.
- Added `Channel` value object and safe segment joining helpers.
- Added maximum channel count per browser connection.
- Added duplicate channel normalization.
- Added `ChannelAuthorizerInterface`.
- Added `UserResolverInterface`.
- Added default `PublicChannelAuthorizer`, which allows only `public.*`.
- Added default `NullUserResolver`, which resolves no authenticated user.
- Added optional `ShieldUserResolver` integration for CodeIgniter Shield.
- Added all-or-nothing channel authorization: if any requested channel is
  denied, the connection is rejected instead of silently subscribing to a
  subset.
- Added CORS origin allowlist and credential support for cross-origin
  frontends.
- Documented secure cookie usage and why bearer tokens should not be placed in
  SSE URLs.
- Added Mercure-specific authorization flow where approved channels become
  exact topic selectors in an HttpOnly subscriber JWT.
- Added expanded Mercure authorization tests for public, user, tenant, project,
  admin, duplicate, and denied channel combinations.

#### Developer tooling and installation

- Added `php spark sse:install` command to publish:
    - application config;
    - browser ES module;
    - TypeScript declarations;
    - broker adapter browser modules.
- Added `php spark sse:health-check` command for broker/config validation.
- Added CodeIgniter service definitions for package services.
- Added Composer package discovery integration for config, routes, services,
  commands, and toolbar registration.
- Added CodeIgniter Debug Toolbar `SSE Events` collector for publish metadata.
- Added traceable publisher decorator used by the toolbar.
- Added coverage upload to Coveralls through GitHub Actions.
- Added CI checks for Composer validation/audit, PHPUnit, PHPStan, Rector,
  coding standards, browser-client tests, documentation build, Redis
  integration, and Mercure integration.

#### Documentation

- Added complete installation guide.
- Added quick-start guide.
- Added configuration reference for route, stream behavior, toolbar, broker,
  Redis, Mercure, authorization, CORS, and credentials.
- Added Redis vs Mercure deployment guidance.
- Added Mercure Hub guide with Docker Compose, keys, cookies, CORS, TLS,
  reverse proxy, replay, and health-check notes.
- Added browser client guide with imports, adapters, channels, named events,
  system events, message shape, error handling, fallback behavior, and
  TypeScript usage.
- Added channels and authorization guide with secure defaults, user resolver,
  authorizer, dependencies, pattern subscriptions, and browser authentication.
- Added streaming/deployment guide for headers, heartbeats, Nginx, Apache,
  PHP-FPM capacity, session locking, Redis networking, CDN/load balancers, and
  larger deployments.
- Added custom broker guide for implementing adapter factories and endpoints.
- Added architecture, events, examples, testing, troubleshooting, upgrade, and
  module-structure documentation.

#### Testing

- Added PHPUnit test suite for core event, broker, HTTP, authorization,
  configuration, stream, support, helper, toolbar, and factory behavior.
- Added browser-client tests with Node's test runner.
- Added Redis integration tests gated by `SSE_REDIS_INTEGRATION=1`.
- Added Mercure integration tests gated by `SSE_MERCURE_INTEGRATION=1`.
- Added recording and fake test support utilities for deterministic broker and
  stream assertions.
- Added Coveralls Clover coverage generation through PHPUnit.

### Security

- Private/user-specific channels are denied by default.
- Every requested channel is treated as untrusted browser input and must pass
  syntax validation and application authorization.
- Channel authorization is required even when route authentication filters are
  configured.
- Mercure subscriber JWTs are written to HttpOnly cookies and are not exposed
  to JavaScript.
- Redis channel prefixes isolate applications sharing the same Redis instance.
- Debug Toolbar collector records publish metadata only and does not display
  event payload values.

### Operational notes

- Redis Pub/Sub is live broadcast only. It does not store events, replay missed
  messages, or guarantee delivery to disconnected clients.
- Business-critical state should remain in the application's database or another
  durable system; SSE events should normally notify the browser that state
  changed.
- Mercure can replay retained Hub history through `Last-Event-ID` when the Hub
  transport is configured for history, but it is not a replacement for
  authoritative application state.
- Redis is the simplest starting point for bounded concurrency. Mercure is
  recommended when many users keep dashboards or notification streams open and
  PHP-FPM workers should remain available for normal requests.

### Requirements

- PHP 8.2 or newer.
- CodeIgniter 4.7 or newer.
- `ext-json`.
- Redis server for the Redis adapter, or Mercure Hub for the Mercure adapter.
- `ext-curl` when using Mercure publishing.

### Upgrade notes

- This is the first release candidate. There are no migrations from an earlier
  stable release.
- Applications should publish the config with `php spark sse:install`, review
  `Config\Sse`, set a unique `channelPrefix`, and implement authorization before
  using private channels.
- For production Redis deployments, configure proxy buffering, PHP-FPM
  capacity, TLS/ACLs where needed, heartbeats, and finite stream lifetime.
- For production Mercure deployments, configure separate publisher/subscriber
  keys, secure cookies, exact CORS origins, HTTPS, and a browser-facing Hub URL.
