# Expiry Pricing

`zarbinco/expiry-pricing` is a Composer library for shelf-life calculations in Laravel applications. Phase 2 calculates expiry dates, shelf-life start dates, and remaining shelf life in the Jalali and Gregorian calendars. Discounts and pricing are planned for Phase 3.

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

## Usage

Use `ExpiryPricingCalculator` directly. Choose `jalali` or `gregorian` and specify the shelf life in `days` or `months`. Dates use `YYYY-MM-DD` in the selected calendar.

```php
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

$calculator = new ExpiryPricingCalculator();

$expiry = $calculator->expiryDate(
    manufacturingDate: '1405-01-01',
    shelfLife: 7,
    unit: 'months',
    calendar: 'jalali',
); // '1405-08-01'

$start = $calculator->shelfLifeStartDate(
    expiryDate: $expiry,
    shelfLife: 7,
    unit: 'months',
    calendar: 'jalali',
); // '1405-01-01'

$result = $calculator->remainingShelfLife(
    date: '1405-01-01',
    dateType: 'manufacturing',
    shelfLife: 7,
    unit: 'months',
    calendar: 'jalali',
    asOfDate: '1405-07-01',
);

$result['totalDays'];           // 216
$result['remainingDays'];       // 30
$result['remainingPercentage']; // About 13.89
$result['expired'];             // false
```

For an expiry-based evaluation, pass the expiry date as `date` and set `dateType` to `expiry`. The result has the same fields and sets `startDateCalculated` to `true`. Month arithmetic clamps the day to the last valid day of the destination month, so calculating a start date can give a different date from the original manufacturing date.

See [the calculation contract](docs/DECISIONS.md) for the full result structure, date ranges, and planned Phase 3 features.

## Development

Run these commands from the package checkout.

```sh
composer install
composer validate --strict
composer test
```

PHPUnit checks Composer autoloading and covers calendar arithmetic, remaining shelf life, and input validation. GitHub Actions runs the same checks on PHP 8.3.
