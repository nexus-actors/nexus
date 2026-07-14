# Changelog

All notable changes to `nexus-actors/observability-serialization` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `TracingMessageSerializer` — tracing decorator for `MessageSerializer`: Internal span per operation, `nexus.serialization.operations` counter, `nexus.serialization.bytes` histogram, `nexus.serialization.duration` histogram, `nexus.serialization.failures` counter, optional PSR-3 warning on failure; zero-overhead pass-through when observability is disabled and no logger is present
