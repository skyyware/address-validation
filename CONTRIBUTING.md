# Contributing

Thank you for helping improve SkyyAddressValidation.

## Development Setup

1. Fork and clone the repository.
2. Install dependencies with `composer update --prefer-dist`.
3. Add focused tests before implementation changes.
4. Run `vendor/bin/phpunit` and `composer validate --strict`.
5. Open a focused pull request with a clear description of behavior and tests.

Use PHP 8.2 or newer and keep changes compatible with Shopware 6.7.
New releases follow the current stable Shopware major line. Older plugin tags
remain installable, but are no longer maintained. Never include customer data,
credentials, proprietary plugin code, or code
derived from unrelated implementations.

By contributing, you agree that your contribution is licensed under the MIT
License included in this repository.
