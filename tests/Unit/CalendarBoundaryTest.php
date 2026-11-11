<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class CalendarBoundaryTest extends TestCase
{
    private const array DURATIONS = [1, 2, 7, 28, 29, 30, 31, 59, 60, 90, 180, 365, 366];

    #[DataProvider('boundaryYears')]
    public function testDaysAndMonthsFromBothDateOriginsAtEveryMonthEdge(string $calendar, int $year): void
    {
        $months = self::civilMonths($calendar, $year - 1, $year + 1);
        $days = array_merge(...$months);
        $ordinals = array_flip($days);
        $calculator = new ExpiryPricingCalculator();

        // The middle twelve months are the year under test. The neighbors are fixtures for shifts.
        for ($month = 12; $month < 24; $month++) {
            $last = array_key_last($months[$month]);
            foreach ([[$month, $last - 1], [$month, $last], [$month + 1, 0]] as [$sourceMonth, $sourceDay]) {
                self::assertCalendarShifts($calculator, $calendar, $months, $days, $ordinals, $sourceMonth, $sourceDay);
            }
        }
    }

    public static function boundaryYears(): iterable
    {
        foreach ([2, 1900, 2000, 2024, 2025, 2100, 2400, 9998] as $year) {
            yield "Gregorian $year" => ['gregorian', $year];
        }

        foreach ([980, 1011, 1012, 1210, 1300, 1354, 1375, 1398, 1399, 1400, 1405, 1634, 9998] as $year) {
            yield "Jalali $year" => ['jalali', $year];
        }
    }

    #[DataProvider('exhaustiveRanges')]
    public function testAllDatesInSelectedMonthsOrYears(string $calendar, int $year, array $selectedMonths): void
    {
        $months = self::civilMonths($calendar, $year - 1, $year + 1);
        $days = array_merge(...$months);
        $ordinals = array_flip($days);
        $calculator = new ExpiryPricingCalculator();

        foreach ($selectedMonths as $month) {
            $monthIndex = $month + 11;
            foreach (array_keys($months[$monthIndex]) as $dayIndex) {
                self::assertCalendarShifts($calculator, $calendar, $months, $days, $ordinals, $monthIndex, $dayIndex);
            }
        }
    }

    public static function exhaustiveRanges(): iterable
    {
        foreach ([1900, 2000, 2100, 2400] as $year) {
            yield "Gregorian February $year" => ['gregorian', $year, [2]];
        }
        yield 'Gregorian 2024 entire leap year' => ['gregorian', 2024, range(1, 12)];

        foreach ([1011, 1012, 1300, 1354, 1375, 1398, 1399, 1634] as $year) {
            yield "Jalali Esfand $year" => ['jalali', $year, [12]];
        }
        yield 'Jalali 1405 entire common year' => ['jalali', 1405, range(1, 12)];
    }

    #[DataProvider('durationCases')]
    public function testShiftsSmallAndLargeDurationsInBothDirections(
        string $calendar,
        string $unit,
        int $duration,
        string $forwardAnchor,
        string $forwardExpected,
        string $backwardAnchor,
        string $backwardExpected,
    ): void {
        $calculator = new ExpiryPricingCalculator();

        self::assertSame($forwardExpected, $calculator->expiryDate($forwardAnchor, $duration, $unit, $calendar));
        self::assertSame($backwardExpected, $calculator->shelfLifeStartDate($backwardAnchor, $duration, $unit, $calendar));
        if ($unit === 'days') {
            self::assertSame($forwardAnchor, $calculator->shelfLifeStartDate($forwardExpected, $duration, $unit, $calendar));
            self::assertSame($backwardAnchor, $calculator->expiryDate($backwardExpected, $duration, $unit, $calendar));
        }
    }

    public static function durationCases(): iterable
    {
        foreach (['gregorian' => 2000, 'jalali' => 1399] as $calendar => $year) {
            // 366 months fit inside this reference window in either direction.
            $months = self::civilMonths($calendar, $year - 31, $year + 31);
            $days = array_merge(...$months);
            $ordinals = array_flip($days);
            // Use day 31 in January/March or Shahrivar in consecutive Jalali years.
            [$forwardMonth, $backwardMonth] = $calendar === 'gregorian' ? [1, 3] : [6, 12 + 6];
            $forwardIndex = 31 * 12 + $forwardMonth - 1;
            $backwardIndex = 31 * 12 + $backwardMonth - 1;
            $forwardAnchor = $months[$forwardIndex][30];
            $backwardAnchor = $months[$backwardIndex][30];

            foreach (self::DURATIONS as $duration) {
                yield "$calendar $duration days" => [
                    $calendar, 'days', $duration,
                    $forwardAnchor, $days[$ordinals[$forwardAnchor] + $duration],
                    $backwardAnchor, $days[$ordinals[$backwardAnchor] - $duration],
                ];
                yield "$calendar $duration months" => [
                    $calendar, 'months', $duration,
                    $forwardAnchor, end($months[$forwardIndex + $duration]),
                    $backwardAnchor, end($months[$backwardIndex - $duration]),
                ];
            }
        }

        // Sum 365 days per year plus Gregorian leap days, then subtract one for the last date.
        yield 'Gregorian entire supported day span' => [
            'gregorian', 'days', 3_652_058, '0001-01-01', '9999-12-31', '9999-12-31', '0001-01-01',
        ];
        // 9,021 years contain 273 complete 33-year cycles and 12 years with 3 more leap days.
        yield 'Jalali entire supported day span' => [
            'jalali', 'days', 3_294_851, '0979-01-01', '9999-12-29', '9999-12-29', '0979-01-01',
        ];
        yield 'Gregorian maximum month shift' => [
            'gregorian', 'months', 119_987, '0001-01-31', '9999-12-31', '9999-12-31', '0001-01-31',
        ];
        yield 'Jalali maximum month shift clamps at the upper boundary' => [
            'jalali', 'months', 108_251, '0979-01-31', '9999-12-29', '9999-12-29', '0979-01-29',
        ];
        yield 'Gregorian day before each supported boundary' => [
            'gregorian', 'days', 1, '9999-12-30', '9999-12-31', '0001-01-02', '0001-01-01',
        ];
        yield 'Jalali day before each supported boundary' => [
            'jalali', 'days', 1, '9999-12-28', '9999-12-29', '0979-01-02', '0979-01-01',
        ];
        yield 'Gregorian month shift to each supported boundary' => [
            'gregorian', 'months', 1, '9999-11-30', '9999-12-30', '0001-02-01', '0001-01-01',
        ];
        yield 'Jalali month shift to each supported boundary' => [
            'jalali', 'months', 1, '9999-11-30', '9999-12-29', '0979-02-01', '0979-01-01',
        ];
        yield 'Gregorian crosses February without clamping in an intermediate month' => [
            'gregorian', 'months', 2, '2100-01-31', '2100-03-31', '2100-03-31', '2100-01-31',
        ];
        yield 'Jalali crosses common Esfand without clamping in an intermediate month' => [
            'jalali', 'months', 2, '1398-11-30', '1399-01-30', '1399-01-31', '1398-11-30',
        ];
    }

    /** @return list<list<string>> Months in chronological order, each containing its valid dates. */
    private static function civilMonths(string $calendar, int $firstYear, int $lastYear): array
    {
        $months = [];
        foreach (range($firstYear, $lastYear) as $year) {
            if ($calendar === 'gregorian') {
                $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
                $lengths = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            } else {
                // Borkowski, The Persian calendar for 3000 years, §1. Extend the fixed cycle as documented.
                $leap = in_array($year % 33, [1, 5, 9, 13, 17, 22, 26, 30], true);
                $lengths = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, $leap ? 30 : 29];
            }

            foreach ($lengths as $month => $length) {
                $months[] = array_map(
                    static fn (int $day): string => sprintf('%04d-%02d-%02d', $year, $month + 1, $day),
                    range(1, $length),
                );
            }
        }

        return $months;
    }

    private static function assertCalendarShifts(
        ExpiryPricingCalculator $calculator,
        string $calendar,
        array $months,
        array $days,
        array $ordinals,
        int $sourceMonth,
        int $sourceDay,
    ): void {
        $source = $months[$sourceMonth][$sourceDay];
        foreach ([-1, 1] as $direction) {
            $destinationMonth = $months[$sourceMonth + $direction];
            $targets = [
                'days' => $days[$ordinals[$source] + $direction],
                'months' => $destinationMonth[$sourceDay] ?? end($destinationMonth),
            ];

            foreach ($targets as $unit => $target) {
                $backward = $direction < 0;
                $dateType = $backward ? 'expiry' : 'manufacturing';
                $context = "$calendar $dateType $source, shift $direction $unit";
                $actual = $backward
                    ? $calculator->shelfLifeStartDate($source, 1, $unit, $calendar)
                    : $calculator->expiryDate($source, 1, $unit, $calendar);
                self::assertSame($target, $actual, $context);

                $total = abs($ordinals[$target] - $ordinals[$source]);
                self::assertSame([
                    'startDate' => $backward ? $target : $source,
                    'expiryDate' => $backward ? $source : $target,
                    'startDateCalculated' => $backward,
                    'totalDays' => $total,
                    'remainingDays' => $backward ? 0 : $total,
                    'remainingPercentage' => $backward ? 0.0 : 100.0,
                    'expired' => $backward,
                ], $calculator->remainingShelfLife($source, $dateType, 1, $unit, $calendar, $source), $context);
            }
        }
    }
}
