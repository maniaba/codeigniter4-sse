# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial CodeIgniter 4 package architecture for publishing and streaming
  Server-Sent Events through Redis Pub/Sub.
- Channel authorization and authenticated-user resolution contracts.
- Streaming response support for supported CodeIgniter releases.
- Framework-independent browser `SseClient` ES module.
- Redis subscriber health PINGs, bounded reconnects, payload/RESP safety
  limits, and event-ID deduplication.
- Mercure 0.x Hub publisher, exact topic mapping, private subscriber JWT
  authorization, direct browser transport, token refresh, and live integration
  tests.
- Installation, configuration, security, deployment, testing, and upgrade
  documentation.
