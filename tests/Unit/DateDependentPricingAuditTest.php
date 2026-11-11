<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class DateDependentPricingAuditTest extends TestCase
{
    private const int PRICE = 10000;

    #[DataProvider('pricingIntervals')]
    public function testQuotesAndFutureTransitionsAtCalendarBoundaries(
        string $calendar,
        string $dateType,
        int $duration,
        string $unit,
        string $start,
        string $expiry,
        int $totalDays,
        array $rules,
        array $quotesByDate,
        array $transitionDates,
    ): void {
        $calculator = new ExpiryPricingCalculator();
        $fromExpiry = $dateType === 'expiry';
        $date = $fromExpiry ? $expiry : $start;
        $expectedTransitions = [];

        foreach ($transitionDates as $effectiveDate) {
            [$remainingDays, $ruleIndex] = $quotesByDate[$effectiveDate];
            $expectedTransitions[] = self::expectedTransition($effectiveDate, $remainingDays, $rules[$ruleIndex]);
        }

        foreach ($quotesByDate as $asOfDate => [$remainingDays, $ruleIndex]) {
            $args = [$date, $dateType, $duration, $unit, $calendar, $asOfDate, self::PRICE, $rules, 0, 1];
            $expectedRule = $ruleIndex === null ? null : $rules[$ruleIndex];
            $quote = $calculator->price(...$args);
            self::assertExpectedQuote($quote, $start, $expiry, $fromExpiry, $totalDays, $remainingDays, $expectedRule);

            $timeline = $calculator->discountTimeline(...$args);
            self::assertSame($quote, $timeline['currentQuote'], $asOfDate);
            self::assertSame($expiry, $timeline['expiryDate'], $asOfDate);
            self::assertSame(
                array_values(array_filter(
                    $expectedTransitions,
                    static fn (array $transition): bool => $transition['effectiveDate'] > $asOfDate,
                )),
                $timeline['transitions'],
                $asOfDate,
            );
        }
    }

    public static function pricingIntervals(): iterable
    {
        // For one day every positive threshold rounds up to the whole shelf life.
        yield 'one Gregorian day crossing a year boundary' => [
            'gregorian', 'manufacturing', 1, 'days', '1999-12-31', '2000-01-01', 1,
            [
                ['remainingThresholdBps' => 0, 'discountBps' => 9000],
                ['remainingThresholdBps' => 1, 'discountBps' => 3000],
                ['remainingThresholdBps' => 9999, 'discountBps' => 1000],
            ],
            [
                '1999-12-30' => [1, 1], '1999-12-31' => [1, 1],
                '2000-01-01' => [0, null], '2000-01-02' => [0, null],
            ],
            [],
        ];

        // For two days both 4999 and 5000 bps yield cutoff 1, an exact quotient at 5000.
        // At equal discounts, the lower threshold wins. Its duplicate yields one transition.
        yield 'two Jalali days ending after leap Esfand' => [
            'jalali', 'expiry', 2, 'days', '1399-12-29', '1400-01-01', 2,
            [
                ['remainingThresholdBps' => 5000, 'discountBps' => 2000],
                ['remainingThresholdBps' => 4999, 'discountBps' => 4000],
                ['remainingThresholdBps' => 5000, 'discountBps' => 4000],
                ['remainingThresholdBps' => 4999, 'discountBps' => 4000],
                ['remainingThresholdBps' => 0, 'discountBps' => 9000],
            ],
            [
                '1399-12-28' => [2, null], '1399-12-29' => [2, null], '1399-12-30' => [1, 1],
                '1400-01-01' => [0, null], '1400-01-02' => [0, null],
            ],
            ['1399-12-30'],
        ];

        // From seven days the 9999, 5000, 3000 and 1 bps cutoffs are 7, 4, 3 and 1.
        // The 9999 bps rule already applies before manufacture because remaining days clamp to 7.
        yield 'seven Gregorian days crossing leap February' => [
            'gregorian', 'manufacturing', 7, 'days', '2000-02-26', '2000-03-04', 7,
            [
                ['remainingThresholdBps' => 1, 'discountBps' => 6000],
                ['remainingThresholdBps' => 5000, 'discountBps' => 2000],
                ['remainingThresholdBps' => 0, 'discountBps' => 9000],
                ['remainingThresholdBps' => 3000, 'discountBps' => 4000],
                ['remainingThresholdBps' => 9999, 'discountBps' => 1000],
            ],
            [
                '2000-02-25' => [7, 4], '2000-02-26' => [7, 4],
                '2000-02-27' => [6, 4], '2000-02-28' => [5, 4], '2000-02-29' => [4, 1],
                '2000-03-01' => [3, 3], '2000-03-02' => [2, 3], '2000-03-03' => [1, 0],
                '2000-03-04' => [0, null], '2000-03-05' => [0, null],
            ],
            ['2000-02-29', '2000-03-01', '2000-03-03'],
        ];

        // The same-day-of-month gaps are 30 + 30 + 30 + 31 + 31 days.
        // CEIL of 91.2, 45.6 and 15.2 yields cutoffs 92, 46 and 16, respectively.
        yield 'five Jalali months crossing leap Esfand from expiry' => [
            'jalali', 'expiry', 5, 'months', '1399-10-15', '1400-03-15', 152,
            [
                ['remainingThresholdBps' => 1000, 'discountBps' => 6000],
                ['remainingThresholdBps' => 6000, 'discountBps' => 2000],
                ['remainingThresholdBps' => 3000, 'discountBps' => 4000],
            ],
            [
                '1399-10-14' => [152, null], '1399-10-15' => [152, null],
                '1399-12-14' => [93, null], '1399-12-15' => [92, 1], '1399-12-16' => [91, 1],
                '1399-12-30' => [77, 1], '1400-01-01' => [76, 1],
                '1400-01-30' => [47, 1], '1400-01-31' => [46, 2], '1400-02-01' => [45, 2],
                '1400-02-29' => [17, 2], '1400-02-30' => [16, 0], '1400-02-31' => [15, 0],
                '1400-03-14' => [1, 0], '1400-03-15' => [0, null], '1400-03-16' => [0, null],
            ],
            ['1399-12-15', '1400-01-31', '1400-02-30'],
        ];
    }

    #[DataProvider('timezoneCases')]
    public function testHistoricalClockChangesDoNotAffectTheFiveDateApis(
        string $timezone,
        string $calendar,
        string $dateType,
        int $duration,
        string $unit,
        string $start,
        string $expiry,
        string $expiryCalculatedFromStart,
        int $totalDays,
        string $discountDate,
        int $cutoff,
    ): void {
        $previousTimezone = date_default_timezone_get();
        self::assertTrue(date_default_timezone_set($timezone));
        try {
            $calculator = new ExpiryPricingCalculator();
            self::assertSame($start, $calculator->shelfLifeStartDate($expiry, $duration, $unit, $calendar));
            self::assertSame($expiryCalculatedFromStart, $calculator->expiryDate($start, $duration, $unit, $calendar));

            $fromExpiry = $dateType === 'expiry';
            $date = $fromExpiry ? $expiry : $start;
            $rule = ['remainingThresholdBps' => 5000, 'discountBps' => 2500];
            $life = $calculator->remainingShelfLife($date, $dateType, $duration, $unit, $calendar, $discountDate);
            self::assertExpectedLife($life, $start, $expiry, $fromExpiry, $totalDays, $cutoff);

            // The transition date is both an output and an input. In Apia it is a skipped local date.
            $quote = $calculator->price(
                $date, $dateType, $duration, $unit, $calendar, $discountDate, self::PRICE, [$rule], 0, 1,
            );
            self::assertExpectedQuote($quote, $start, $expiry, $fromExpiry, $totalDays, $cutoff, $rule);
            $timeline = $calculator->discountTimeline(
                $date, $dateType, $duration, $unit, $calendar, $start, self::PRICE, [$rule], 0, 1,
            );
            self::assertExpectedQuote($timeline['currentQuote'], $start, $expiry, $fromExpiry, $totalDays, $totalDays, null);
            self::assertSame($expiry, $timeline['expiryDate']);
            self::assertSame([self::expectedTransition($discountDate, $cutoff, $rule)], $timeline['transitions']);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public static function timezoneCases(): iterable
    {
        // Historical transition dates come from IANA tzdb. The expected values count date labels.
        $fixtures = [
            'Apia date-line jump' => [
                'gregorian', 'expiry', 2, 'days', '2011-12-29', '2011-12-31', '2011-12-31', 2, '2011-12-30', 1,
            ],
            'New York spring DST change' => [
                'gregorian', 'manufacturing', 3, 'days', '2021-03-13', '2021-03-16', '2021-03-16', 3, '2021-03-14', 2,
            ],
            'Berlin autumn DST change' => [
                'gregorian', 'expiry', 3, 'days', '2021-10-30', '2021-11-02', '2021-11-02', 3, '2021-10-31', 2,
            ],
            'Tehran midnight DST change on 2021-03-22' => [
                'jalali', 'manufacturing', 3, 'days', '1400-01-01', '1400-01-04', '1400-01-04', 3, '1400-01-02', 2,
            ],
            'expiry-only Jalali month spanning leap Esfand and Tehran DST change' => [
                'jalali', 'expiry', 1, 'months', '1399-12-30', '1400-01-31', '1400-01-30', 31, '1400-01-15', 16,
            ],
        ];

        foreach (['UTC', 'Asia/Tehran', 'Europe/Berlin', 'America/New_York', 'Pacific/Apia'] as $timezone) {
            foreach ($fixtures as $label => $fixture) {
                yield "$timezone: $label" => [$timezone, ...$fixture];
            }
        }
    }

    private static function expectedTransition(string $effectiveDate, int $remainingDays, array $rule): array
    {
        return [
            'effectiveDate' => $effectiveDate,
            'remainingDays' => $remainingDays,
            'discountBps' => $rule['discountBps'],
            'finalPrice' => self::PRICE - $rule['discountBps'],
            'matchedRule' => $rule,
        ];
    }

    private static function assertExpectedQuote(
        array $quote,
        string $start,
        string $expiry,
        bool $fromExpiry,
        int $totalDays,
        int $remainingDays,
        ?array $rule,
    ): void {
        $discount = $rule['discountBps'] ?? 0;
        self::assertSame(self::PRICE, $quote['originalPrice']);
        self::assertSame(self::PRICE - $discount, $quote['finalPrice']);
        self::assertSame($discount, $quote['discountBps']);
        self::assertSame($discount, $quote['effectiveDiscountBps']);
        self::assertSame($rule, $quote['matchedRule']);
        self::assertFalse($quote['priceFloorApplied']);
        self::assertFalse($quote['roundingApplied']);
        self::assertExpectedLife($quote['shelfLife'], $start, $expiry, $fromExpiry, $totalDays, $remainingDays);
    }

    private static function assertExpectedLife(
        array $life,
        string $start,
        string $expiry,
        bool $fromExpiry,
        int $totalDays,
        int $remainingDays,
    ): void {
        self::assertSame($start, $life['startDate']);
        self::assertSame($expiry, $life['expiryDate']);
        self::assertSame($fromExpiry, $life['startDateCalculated']);
        self::assertSame($totalDays, $life['totalDays']);
        self::assertSame($remainingDays, $life['remainingDays']);
        self::assertEqualsWithDelta(100 * $remainingDays / $totalDays, $life['remainingPercentage'], 1e-9);
        self::assertSame($remainingDays === 0, $life['expired']);
    }
}
