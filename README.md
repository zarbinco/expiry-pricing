# Expiry Pricing

`zarbinco/expiry-pricing` is a Composer library for shelf-life and expiry-based pricing in Laravel applications. It calculates expiry dates, shelf-life start dates, remaining shelf life, price quotes, and future discount transitions in the Jalali and Gregorian calendars.

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

## Pricing quotes

Pass prices in integer minor units and percentages in integer basis points. Here, the rules offer 20% off at the 30% shelf-life threshold and 40% off at the 10% threshold.

```php
$rules = [
    ['remainingThresholdBps' => 3_000, 'discountBps' => 2_000],
    ['remainingThresholdBps' => 1_000, 'discountBps' => 4_000],
];

$quote = $calculator->price(
    date: '1405-01-01',
    dateType: 'manufacturing',
    shelfLife: 7,
    unit: 'days',
    calendar: 'jalali',
    asOfDate: '1405-01-06',
    originalPrice: 100_000,
    rules: $rules,
    minimumPriceRatioBps: 0,
    roundingStep: 1,
);

$quote['shelfLife']['remainingDays']; // 2
$quote['discountBps'];                // 2000 (20%)
$quote['finalPrice'];                 // 80000
```

Use `dateType: 'expiry'` to quote from a known expiry date. Here, the 65% price floor takes priority over the 100-unit rounding step.

```php
$quote = $calculator->price(
    date: '2025-12-08',
    dateType: 'expiry',
    shelfLife: 7,
    unit: 'days',
    calendar: 'gregorian',
    asOfDate: '2025-12-07',
    originalPrice: 10_005,
    rules: $rules,
    minimumPriceRatioBps: 6_500,
    roundingStep: 100,
);

$quote['shelfLife']['startDate']; // '2025-12-01'
$quote['discountBps'];           // 4000 (40%)
$quote['finalPrice'];            // 6504
$quote['priceFloorApplied'];     // true
```

`roundingStep` accepts any positive PHP integer in the same monetary unit as the prices. The calculator rounds to the nearest multiple using `HALF_UP`, then applies the price floor and caps the result at the original price. Either boundary can produce a final price between step multiples. The calling application handles any currency conversion.

For example, a 25% discount on `250_000` minor units gives `187_500` before step rounding. With a zero price floor, a step of `10_000` gives `190_000` and a step of `100_000` gives `200_000`.

On and after the expiry date, the quote reports expiry and returns the original price.

## Discount timelines

`discountTimeline()` takes the same arguments as `price()` and works from either a manufacturing date or an expiry date in either calendar. Reuse the rules above to see when the selected discount changes for a seven-day Jalali shelf life.

```php
$timeline = $calculator->discountTimeline(
    date: '1405-01-01',
    dateType: 'manufacturing',
    shelfLife: 7,
    unit: 'days',
    calendar: 'jalali',
    asOfDate: '1405-01-01',
    originalPrice: 100_000,
    rules: $rules,
    minimumPriceRatioBps: 0,
    roundingStep: 1,
);

$timeline['currentQuote']['discountBps']; // 0
$timeline['expiryDate'];                  // '1405-01-08'
array_column($timeline['transitions'], 'discountBps', 'effectiveDate');
// ['1405-01-05' => 2000, '1405-01-07' => 4000]
```

The timeline lists changes in the selected discount strictly after `asOfDate` and before expiry. A floor or rounding can keep the final price the same across a transition. See [the calculation contract](docs/DECISIONS.md) for result fields, validation, and rounding rules.

## Development

Run these commands from the package checkout.

```sh
composer install
composer validate --strict
composer test
```

PHPUnit checks Composer autoloading and covers calendar arithmetic, remaining shelf life, pricing, discount timelines, and input validation. GitHub Actions runs the same checks on PHP 8.3.
