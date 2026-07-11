# SkyyAddressValidation

SkyyAddressValidation is a clean-room, MIT-licensed Shopware 6 plugin for
international customer input checks and optional remote address verification.
It supports Shopware 6.6 and 6.7 on PHP 8.2 or newer.

## Safe Defaults

The three local character checks are enabled by default. Remote verification is
disabled, no provider endpoint or merchant identity is preconfigured, and
provider normalization is disabled. A merchant must explicitly configure and
enable any remote provider.

## Installation

Place the plugin in a Shopware project's `custom/plugins/SkyyAddressValidation`
directory or install the Composer package, then run:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SkyyAddressValidation
```

## Development

Install dependencies and run the focused unit suite:

```bash
composer update --prefer-dist
vendor/bin/phpunit
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidance and
[SECURITY.md](SECURITY.md) for private vulnerability reporting.

## License

SkyyAddressValidation is released under the [MIT License](LICENSE).
