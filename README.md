# Expiry Pricing

`zarbinco/expiry-pricing` is a Composer library for shelf-life and expiry-based pricing in Laravel applications. Phase 1 sets up the package, dependency requirements, and tests. The calculations are planned for a later phase.

## Requirements

- PHP 8.3 or later in the PHP 8 series
- Laravel 13.x
- Composer 2, which installs Verta 9 as a dependency

## Installation

Once this package is available on the repository's `main` branch, run these commands from a Laravel 13 application to install the development version.

```sh
composer config repositories.expiry-pricing vcs https://github.com/zarbinco/expiry-pricing.git
composer require "zarbinco/expiry-pricing:dev-main"
```

## Planned features

- Jalali and Gregorian date calculations with an explicit calendar and shelf life in days or calendar months
- Expiry dates from manufacturing dates and calculated shelf-life start dates from expiry dates
- Remaining days, remaining percentage, and expired status as of a caller-supplied date
- Percentage-based discounts, minimum price ratios, and upcoming discount transitions

See [the calculation contract](docs/DECISIONS.md) for the proposed API and open decisions.

## Development

Run these commands from the package checkout.

```sh
composer install
composer validate --strict
composer test
```

PHPUnit checks Composer's namespace registration and dependency autoloading. GitHub Actions runs the same checks on PHP 8.3.
