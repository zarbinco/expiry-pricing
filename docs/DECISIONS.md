# Calculation decisions

Phase 2 implements date and shelf-life calculations. Discount thresholds and pricing are planned for Phase 3.

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

Format `remainingPercentage` as needed for display. Future threshold comparisons will use the integer day counts to preserve the exact ratio.

## Phase 3 discount thresholds

Define each rule as an inclusive percentage of remaining shelf life and its associated discount. For example, a policy may give a 20% discount at 30% remaining or less and a 40% discount at 10% remaining or less.

Convert each percentage threshold to a whole-day cutoff using the total shelf-life days. The default rounding mode is `CEIL`. On a seven-day shelf life, a 10% threshold qualifies at one remaining day while the actual percentage is still `1/7 × 100`. When several rules qualify, including rules whose rounded cutoffs coincide, select the largest discount. The selected discount is zero when no rule qualifies.

## Phase 3 pricing and previews

Use integer minor monetary units for prices and exact arithmetic for discount rates and minimum price ratios. Apply the selected percentage discount, enforce the minimum allowed ratio of the original price, and round to a whole minor unit under a defined monetary rounding policy. The final rounded price must respect the minimum.

Return the original price, selected discount percentage, final price, and shelf-life evaluation. Include the matched rule and the effect of the price floor and rounding so an administrative preview can explain the result. Return future dates when the selected discount changes under the supplied policy. Rules with coincident cutoffs yield one transition showing the winning discount.

The proposed `price()` method will accept either a manufacturing or an expiry date along with the shelf-life parameters, reference date, original price in minor units, and pricing policy. The policy will supply threshold and discount pairs, the minimum price ratio, and rounding options.

## Package boundary

The package performs calculations only. The consuming Laravel application owns products, warehouses, database records, inventory reservations, FEFO allocation, checkout workflows, policy publishing, authentication, and business-specific sellability rules. Expired status is available to the application for its own decisions.

## Decisions for Phase 3

Choose the exact representation and public precision for discount and price ratios and percentages, the default monetary rounding mode, and the rounding modes callers can select.
