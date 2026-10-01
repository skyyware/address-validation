# SkyyAddressValidation

SkyyAddressValidation is an MIT-licensed Shopware 6 plugin for international
customer input checks and optional remote address verification. It supports
Shopware 6.7 on PHP 8.2 or newer.

## Compatibility

New releases support the current stable Shopware major line only, currently
6.7. Version `0.2.x` requires Shopware 6.7. Version `0.1.0` remains available
for Shopware 6.6, but receives no further fixes. Existing tags are never changed.

## What It Checks

The local checks run entirely inside Shopware and are enabled by default:

- first and last names may use international scripts, punctuation and combining
  marks, but not digits;
- telephone numbers may contain digits and common dialing separators, but not
  letters or control characters;
- postal codes may use international letters, digits, spaces and hyphens.

These checks validate input shape. They do not claim that a person, telephone
number, postal code or address exists.

## Safe Defaults

Remote verification is disabled by default. No endpoint, user agent, merchant
identity or public Nominatim service is preconfigured. Address normalization is
also disabled by default, so enabling a provider does not silently rewrite
customer input.

Configure remote verification only with a self-hosted or contracted
Nominatim-compatible HTTPS endpoint whose capacity, usage policy and service
level fit the shop. The public Nominatim service is not recommended for
production or commercial checkout traffic.

## Provider Operation

For a complete address, the adapter sends structured street, city, postal-code
and country fields to the configured endpoint. An exact canonical match is
accepted. A non-matching candidate is returned as a suggestion and blocks the
write with a translated field error. Transport, configuration and malformed
response failures fail open, leaving checkout available.

Verified and rejected results are cached for 30 days. Provider-unavailable
results are cached for five minutes. Cache keys are one-way hashes, while the
cached result may contain the normalized or suggested address. A non-blocking
gate permits one request per second per installation and prevents concurrent
provider calls; denied calls fail open. Cache read failures bypass the cache,
and cache write failures never discard a provider result.

## Privacy And Provider Policy

Remote address verification sends personal data to another system, using a GET
request compatible with the configured provider. The merchant remains
responsible for a legal basis, data protection assessment, processor agreement,
privacy notice, retention policy, access controls and deletion procedures.
Review proxy and provider URL-log retention as part of that assessment.

The configured user agent and contact address identify the merchant. The
merchant is also responsible for any attribution the provider or underlying
data license requires. SkyyAddressValidation never logs submitted addresses,
provider response bodies or cache values.

## Installation

Install the package with Composer:

```bash
composer require skyyware/address-validation:^0.2
bin/console plugin:refresh
bin/console plugin:install --activate SkyyAddressValidation
```

For a ZIP installation, place the extracted `SkyyAddressValidation` directory
in `custom/plugins` before running the two plugin commands above.

Configuration is available in the Shopware Administration under Extensions.
Keep remote verification off until all provider and privacy requirements have
been reviewed.

## Uninstall

Uninstalling removes the plugin settings. It does not undo addresses previously
normalized through the optional provider.

## Development

Install dependencies and run the complete local quality gate:

```bash
composer update --prefer-dist
bin/check
```

Local release checks target the current stable Shopware 6.7 release on PHP 8.2
and 8.4. Real integration tests install the clean release ZIP into a disposable
Shopware project before booting the container. GitHub Actions is disabled.

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidance and
[SECURITY.md](SECURITY.md) for private vulnerability reporting.

## License

SkyyAddressValidation is released under the [MIT License](LICENSE).
