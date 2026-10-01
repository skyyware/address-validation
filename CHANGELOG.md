# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-01

- Support Shopware 6.7 only. Use `0.1.0` for existing Shopware 6.6 projects.
- Require Symfony 7 and PHPUnit 11; remove the older compatibility matrix.
- Run dependency audits with the local release checks.

## [0.1.0] - 2026-07-12

### Added

- International server-side validation for names, telephone numbers and postal
  codes.
- Optional provider-neutral address verification with a Nominatim-compatible
  HTTPS adapter.
- Privacy-safe defaults, explicit normalization opt-in and sanitized logging.
- Persistent result caching and a non-blocking request gate.
- Shopware 6.6 and 6.7 compatibility and packaged-runtime integration tests.
