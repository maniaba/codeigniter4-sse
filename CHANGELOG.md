# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial CodeIgniter 4 package architecture for publishing and streaming
  Server-Sent Events through Redis Pub/Sub.
- Channel authorization and authenticated-user resolution contracts.
- Native CodeIgniter 4.8 SSE response support with a transparent compatibility
  path for older supported CodeIgniter releases.
- Framework-independent browser `SseClient` ES module.
- Redis subscriber health PINGs, bounded reconnects, payload/RESP safety
  limits, and event-ID deduplication.
- Installation, configuration, security, deployment, testing, and upgrade
  documentation.
