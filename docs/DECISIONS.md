# Calculation decisions

Phase 1 establishes the package foundation. This document describes the calculation contract and proposes a public API for Phase 2.

## Calendar and date arithmetic

The caller must explicitly select Jalali (Persian) or Gregorian. All dates in a calculation use that calendar. The package accepts date-only values and takes the reference date from the caller, so results are independent of the system clock and timezone.

Shelf life is expressed as a duration in days or calendar months. Month arithmetic moves directly to the destination month and preserves the day of month when valid. If the day is invalid, it clamps to the last valid day of the destination month. Month lengths follow the selected calendar and its leap-year rules.

- Given an actual manufacturing date and shelf life, calculate the expiry date by adding the duration.
- Given an expiry date and shelf life, calculate the shelf-life start date by subtracting the duration. Label that date as calculated and retain the expiry date supplied by the caller.

Month addition and subtraction can produce different endpoints on a round trip. In Gregorian 2025, adding one month to January 31 gives February 28. Subtracting one month from February 28 gives January 28. When evaluating shelf life, use the endpoints derived from the supplied date so that this difference is preserved.

## Shelf-life evaluation

Calculate total shelf-life days from the start and expiry dates, even when the duration was supplied in months. Return total days, remaining days, the actual remaining percentage, and whether the item has expired as of the caller's reference date. Use the ratio of remaining days to total days for the actual percentage and keep threshold rounding separate.

The proposed boundary treats the expiry date as the first expired day. At expiry and afterward, the remaining days and percentage are zero. On the shelf-life start date and before it, the proposed result caps remaining days at the total and the percentage at 100%.

## Discount thresholds

Define each rule as an inclusive percentage of remaining shelf life and its associated discount. For example, a policy may give a 20% discount at 30% remaining or less and a 40% discount at 10% remaining or less.

Convert each percentage threshold to a whole-day cutoff using the total shelf-life days. The default rounding mode is `CEIL`. On a seven-day shelf life, a 10% threshold qualifies at one remaining day while the actual percentage is still `1/7 × 100`. When several rules qualify, including rules whose rounded cutoffs coincide, select the largest discount. The selected discount is zero when no rule qualifies.

## Pricing and previews

Use integer minor monetary units for prices and exact arithmetic for discount rates and minimum price ratios. Apply the selected percentage discount, enforce the minimum allowed ratio of the original price, and round to a whole minor unit under a defined monetary rounding policy. The final rounded price must respect the minimum.

Return the original price, selected discount percentage, final price, and shelf-life evaluation. Include the matched rule and the effect of the price floor and rounding so an administrative preview can explain the result. Return future dates when the selected discount changes under the supplied policy. Rules with coincident cutoffs yield one transition showing the winning discount.

## Proposed public API

Expose an ordinary `Zarbinco\ExpiryPricing\ExpiryPricingCalculator` that Laravel services can use directly. The following are proposed call shapes.

| Method | Inputs | Result |
| --- | --- | --- |
| `expiryDate()` | Manufacturing date, duration, unit, calendar | Calculated expiry date |
| `shelfLifeStartDate()` | Expiry date, duration, unit, calendar | Calculated shelf-life start date |
| `remainingShelfLife()` | Shelf-life input and reference date | Start and expiry dates, start-date origin, total days, remaining days, remaining percentage, expired status |
| `price()` | Shelf-life input, reference date, original price in minor units, and pricing policy | Shelf-life evaluation, original and final prices, selected discount, calculation details, future discount transitions |

The shelf-life input carries one date, its kind (`manufacturing` or `expiry`), the duration, unit, and calendar. Both date kinds work with `remainingShelfLife()` and `price()`. The pricing policy supplies the threshold and discount pairs, minimum price ratio, and rounding options.

## Package boundary

The package performs calculations only. The consuming Laravel application owns products, warehouses, database records, inventory reservations, FEFO allocation, checkout workflows, policy publishing, authentication, and business-specific sellability rules. Expired status is available to the application for its own decisions.

## Decisions to finalize before implementation

- Confirm the proposed expiry-day and pre-start boundaries.
- Define the date format, supported date and duration ranges, and invalid-input behavior.
- Choose the exact representation and public precision for ratios and percentages, the default monetary rounding mode, and which rounding modes callers can select.
