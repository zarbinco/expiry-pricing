<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class DateInputBoundaryTest extends TestCase
{
    #[DataProvider('invalidDates')]
    public function testRejectsAnInvalidDateInEveryDateRole(string $calendar, string $invalid): void
    {
        $calculator = new ExpiryPricingCalculator();
        $valid = $calendar === 'gregorian' ? '2025-04-15' : '1405-04-15';

        self::assertInvalid('manufacturingDate', static fn () => $calculator->expiryDate($invalid, 1, 'days', $calendar));
        self::assertInvalid('expiryDate', static fn () => $calculator->shelfLifeStartDate($invalid, 1, 'days', $calendar));
        foreach (['manufacturing', 'expiry'] as $dateType) {
            self::assertInvalid('date', static fn () =>
                $calculator->remainingShelfLife($invalid, $dateType, 1, 'days', $calendar, $valid));
            self::assertInvalid('asOfDate', static fn () =>
                $calculator->remainingShelfLife($valid, $dateType, 1, 'days', $calendar, $invalid));
        }
    }

    public static function invalidDates(): iterable
    {
        foreach (['gregorian' => '2025', 'jalali' => '1405'] as $calendar => $year) {
            foreach ([
                'empty' => '',
                'unstructured' => 'date',
                'wrong separators' => "$year/01/01",
                'unpadded month' => "$year-1-01",
                'unpadded day' => "$year-01-1",
                'trailing characters' => "$year-01-01extra",
                'leading space' => " $year-01-01",
                'trailing space' => "$year-01-01 ",
                'trailing newline' => "$year-01-01\n",
                'local time' => "$year-01-01 00:00:00",
                'ISO timestamp' => "{$year}-01-01T00:00:00Z",
                'year zero' => '0000-01-01',
                'negative year' => '-0001-01-01',
                'year above maximum' => '10000-01-01',
                'month zero' => "$year-00-01",
                'month thirteen' => "$year-13-01",
                'day zero' => "$year-01-00",
                'day thirty-two' => "$year-01-32",
                'Persian digits' => "$year-۰۱-۰۱",
                'Arabic-Indic digits' => "$year-٠١-٠١",
            ] as $label => $date) {
                yield "$calendar $label" => [$calendar, $date];
            }
        }

        foreach (['1900-02-29', '2024-02-30', '2025-02-29', '2025-04-31', '2100-02-29'] as $date) {
            yield "invalid Gregorian date $date" => ['gregorian', $date];
        }
        foreach (['0978-12-29', '1398-12-30', '1400-12-30', '1405-07-31', '1405-11-31', '9999-12-30'] as $date) {
            yield "invalid Jalali date $date" => ['jalali', $date];
        }
    }

    #[DataProvider('invalidShiftDurations')]
    public function testRejectsInvalidShiftDurationsInBothDirections(
        string $calendar,
        string $unit,
        int $duration,
        string $forwardAnchor,
        string $backwardAnchor,
        string $message,
    ): void {
        $calculator = new ExpiryPricingCalculator();

        self::assertInvalid($message, static fn () =>
            $calculator->expiryDate($forwardAnchor, $duration, $unit, $calendar));
        self::assertInvalid($message, static fn () =>
            $calculator->shelfLifeStartDate($backwardAnchor, $duration, $unit, $calendar));
        self::assertInvalid($message, static fn () =>
            $calculator->remainingShelfLife($forwardAnchor, 'manufacturing', $duration, $unit, $calendar, $forwardAnchor));
        self::assertInvalid($message, static fn () =>
            $calculator->remainingShelfLife($backwardAnchor, 'expiry', $duration, $unit, $calendar, $backwardAnchor));
    }

    public static function invalidShiftDurations(): iterable
    {
        foreach ([
            'gregorian' => ['0001-01-01', '9999-12-31', ['days' => 3_652_058, 'months' => 119_987]],
            'jalali' => ['0979-01-01', '9999-12-29', ['days' => 3_294_851, 'months' => 108_251]],
        ] as $calendar => [$first, $last, $limitByUnit]) {
            foreach ($limitByUnit as $unit => $limit) {
                $range = "outside the supported $calendar date range";
                yield "$calendar $unit one beyond either boundary" => [$calendar, $unit, 1, $last, $first, $range];
                yield "$calendar $unit just above maximum" => [$calendar, $unit, $limit + 1, $first, $last, $range];
                yield "$calendar $unit PHP_INT_MAX" => [$calendar, $unit, PHP_INT_MAX, $first, $last, $range];
                foreach ([0, -1, PHP_INT_MIN] as $duration) {
                    yield "$calendar $unit $duration" => [$calendar, $unit, $duration, $first, $last, 'positive integer'];
                }
            }
        }
    }

    public function testRejectsInvalidCalendarUnitAndDateType(): void
    {
        $calculator = new ExpiryPricingCalculator();
        $date = '2025-04-15';

        self::assertInvalid('calendar', static fn () => $calculator->shelfLifeStartDate($date, 1, 'days', 'Gregorian'));
        self::assertInvalid('unit', static fn () => $calculator->shelfLifeStartDate($date, 1, 'weeks', 'gregorian'));
        self::assertInvalid('dateType', static fn () =>
            $calculator->remainingShelfLife($date, 'Expiry', 1, 'days', 'gregorian', $date));
    }

    private static function assertInvalid(string $message, callable $operation): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());

            return;
        }

        self::fail("Expected InvalidArgumentException mentioning $message.");
    }
}
