# Calculation decisions

The package implements date and shelf-life calculations, expiry-based pricing, and discount timelines.

## Inputs and validation

The caller must pass `jalali` (Persian) or `gregorian` explicitly. All dates in a call use the selected calendar and the format `YYYY-MM-DD` with ASCII digits and zero-padded months and days. Gregorian years `0001` through `9999` and Jalali years `0979` through `9999` are supported. The Jalali lower bound keeps date conversion within Verta's supported behavior. `remainingShelfLife()` requires an explicit `asOfDate`. Results are independent of the system clock and timezone.

`shelfLife` is a positive integer and `unit` is `days` or `months`. The maximum duration depends on the supplied date, unit, and direction because the calculated date must fit within the selected calendar's supported range.

Invalid calendar or unit values, non-positive durations, invalid date types, incorrect date formats, and impossible calendar dates such as Gregorian `2025-02-30` raise `InvalidArgumentException`. The same exception applies if an input or calculated date falls outside the supported range. PHP's scalar type rules apply to the typed parameters.

## Date arithmetic

Day arithmetic advances or goes back by the specified number of calendar days. Month arithmetic moves directly to the destination month and preserves the day of month when valid. If the destination month is shorter, the calculation clamps to its last valid day. Month lengths follow Gregorian leap-year rules or Verta's 33-year Jalali leap-year cycle, according to the selected calendar.

- Given an actual manufacturing date and shelf life, calculate the expiry date by adding the duration.
- Given an expiry date and shelf life, calculate the shelf-life start date by subtracting the duration. Label that date as calculated and retain the expiry date supplied by the caller.

Month addition and subtraction can produce different endpoints on a round trip. Adding a month to Jalali `1405-06-31` gives `1405-07-30`. Subtracting a month from that expiry gives `1405-06-30`. Similarly, adding a month to Gregorian `2025-01-31` gives `2025-02-28`, which yields `2025-01-28` when subtracting a month. Shelf-life evaluation uses the endpoints derived from the caller's supplied date.

## Public API

Use `Zarbinco\ExpiryPricing\ExpiryPricingCalculator` directly in PHP or in a Laravel service.

| Method | Parameters in order | Result |
| --- | --- | --- |
| `expiryDate()` | `manufacturingDate`, `shelfLife`, `unit`, `calendar` | Calculated expiry date as a string |
| `shelfLifeStartDate()` | `expiryDate`, `shelfLife`, `unit`, `calendar` | Calculated shelf-life start date as a string |
| `remainingShelfLife()` | `date`, `dateType`, `shelfLife`, `unit`, `calendar`, `asOfDate` | Associative array with the fields below |
| `price()` | `date`, `dateType`, `shelfLife`, `unit`, `calendar`, `asOfDate`, `originalPrice`, `rules`, `minimumPriceRatioBps`, `roundingStep` | Pricing quote with the shelf-life result |
| `discountTimeline()` | Same required parameters as `price()` | Current quote, expiry date, and future discount transitions |

Set `dateType` to `manufacturing` when supplying a manufacturing date or `expiry` when supplying an expiry date.

## Shelf-life evaluation

`totalDays` is the calendar-day difference from start to expiry, including for durations supplied in months. `remainingDays` is the calendar-day difference from `asOfDate` to expiry, clamped to zero through `totalDays`. On and before the start date, the full shelf life remains. The expiry date is the first expired day. On and after that date, the remaining days and percentage are zero.

| Field | Type | Meaning |
| --- | --- | --- |
| `startDate` | `string` | Start date in the selected calendar |
| `expiryDate` | `string` | Expiry date in the selected calendar |
| `startDateCalculated` | `bool` | `true` when the start was calculated from an expiry date, `false` when the caller supplied a manufacturing date |
| `totalDays` | `int` | Full shelf life in calendar days |
| `remainingDays` | `int` | Shelf life remaining as of `asOfDate` |
| `remainingPercentage` | `float` | `remainingDays / totalDays × 100` without display rounding |
| `expired` | `bool` | `true` on or after the expiry date |

Format `remainingPercentage` as needed for display. Discount thresholds use the integer day counts.

## Discount rules

Pass `rules` as an array. Each rule must contain exactly two keys, `remainingThresholdBps` and `discountBps`. Both values are integers in basis points from `0` through `10000`, inclusive. For example, `['remainingThresholdBps' => 3000, 'discountBps' => 2000]` represents a 20% discount at a 30% threshold. The keys of the outer array have no effect.

For each rule, the whole-day cutoff is `CEIL(totalDays × remainingThresholdBps / 10000)`. A rule qualifies when `remainingDays` is at or below its cutoff. On a seven-day shelf life, 30% and 10% yield cutoffs of three days and one day. At one remaining day, the reported percentage remains `1/7 × 100`. On a 216-day shelf life, the same rules yield cutoffs of 65 and 22 days.

The highest qualifying `discountBps` wins. If discounts are equal, the lower `remainingThresholdBps` wins. Identical rules produce the same match in any order. An empty rule set or a lack of qualifying rules yields a zero discount and a `null` match. A qualifying rule with a zero discount may still appear as the match.

On and after the expiry date, the quote returns the original price, zero discount, a `null` match, and `shelfLife.expired` set to `true`. Pricing validates every rule, including when the item is already expired.

## Monetary calculation

Supply `originalPrice` in integer minor units from `0` through `intdiv(PHP_INT_MAX, 10000)`. This cap keeps price and basis-point products within the integer range. The minimum price ratio is an integer from `0` through `10000` basis points. Set `roundingStep` to `1` to keep single minor units, `100` for the nearest hundred, or `1000` for the nearest thousand. All pricing arguments are required. Numeric arguments, including `shelfLife` and rule values, must be PHP integers. Invalid numeric values and malformed rules raise `InvalidArgumentException`.

For a positive selected discount, the calculator applies these operations in order using integer arithmetic.

1. Calculate `originalPrice × (10000 - discountBps) / 10000` and round to the nearest minor unit using `HALF_UP`.
2. Round the result to the nearest multiple of `roundingStep` using `HALF_UP`. In both rounding stages, exact midpoints round upward.
3. Raise the rounded price to `CEIL(originalPrice × minimumPriceRatioBps / 10000)` if it falls below that floor. Cap the result at `originalPrice`. Both boundaries take precedence over the step, so the final price can fall between step multiples.
4. Calculate `effectiveDiscountBps` from the difference between the original and final prices. Round `(originalPrice - finalPrice) × 10000 / originalPrice` to the nearest basis point using `HALF_UP`. An original price of zero yields zero effective discount.

When the selected discount is zero, the calculator returns the original price with both adjustment flags set to `false`.

## Price result

| Field | Type | Meaning |
| --- | --- | --- |
| `originalPrice` | `int` | Input price in minor units |
| `finalPrice` | `int` | Final price in minor units after rounding and boundaries |
| `discountBps` | `int` | Nominal discount from the selected rule in basis points, or zero |
| `effectiveDiscountBps` | `int` | Actual price reduction in basis points after adjustments |
| `matchedRule` | `array` or `null` | Selected rule with `remainingThresholdBps` and `discountBps`, or `null` |
| `priceFloorApplied` | `bool` | Whether the minimum floor raised the rounded price |
| `roundingApplied` | `bool` | Whether minor-unit rounding or step rounding changed the amount before enforcing boundaries |
| `shelfLife` | `array` | Result from `remainingShelfLife()` for the supplied dates |

## Discount timeline

`discountTimeline()` uses the same validation and pricing rules as `price()`. It calculates `currentQuote` with `price()` at the supplied `asOfDate` and uses the quote's calculated shelf-life dates and total days.

For each rule, the candidate date is `expiryDate - CEIL(totalDays × remainingThresholdBps / 10000)` calendar days. The calculator evaluates each distinct candidate strictly after `asOfDate` and before expiry using `price()`, in chronological order. It records a transition when the nominal `discountBps` changes, even if the final price stays the same. Rules that qualify together yield one quote for the winning discount.

The current quote already accounts for discounts that apply on the reference date, including a cutoff at the full shelf life when evaluated on or before the start date. Expiry appears separately, with `currentQuote['shelfLife']['expired']` describing the status on `asOfDate`. A zero-percent threshold has its candidate on the expiry date, outside the transition window. On or after expiry, or when the selected discount has no future changes, `transitions` is empty.

| Field | Type | Meaning |
| --- | --- | --- |
| `currentQuote` | `array` | Full result from `price()` at `asOfDate` |
| `expiryDate` | `string` | Expiry date in the selected calendar |
| `transitions` | `list` | Chronological transition entries |

Each transition contains these fields.

| Field | Type | Meaning |
| --- | --- | --- |
| `effectiveDate` | `string` | First date of the new nominal discount in the selected calendar |
| `remainingDays` | `int` | Shelf-life days remaining on that date |
| `discountBps` | `int` | Nominal discount in basis points |
| `finalPrice` | `int` | Price in minor units after rounding and boundaries |
| `matchedRule` | `array` | Selected rule with `remainingThresholdBps` and `discountBps` |

## Package boundary

The package performs calculations only. The consuming Laravel application owns products, warehouses, database records, inventory reservations, FEFO allocation, checkout workflows, policy publishing, authentication, and business-specific sellability rules. Expired status is available to the application for its own decisions.
