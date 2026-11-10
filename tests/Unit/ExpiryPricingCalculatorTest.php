<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class ExpiryPricingCalculatorTest extends TestCase
{
    #[DataProvider('dateArithmeticCases')]
    public function testCalculatesExpiryAndShelfLifeStartDates(
        string $calendar,
        string $unit,
        int $shelfLife,
        string $manufacturingDate,
        string $expiryDate,
        string $calculatedStartDate,
    ): void {
        $calculator = new ExpiryPricingCalculator();

        self::assertSame($expiryDate, $calculator->expiryDate(
            manufacturingDate: $manufacturingDate,
            shelfLife: $shelfLife,
            unit: $unit,
            calendar: $calendar,
        ));
        self::assertSame($calculatedStartDate, $calculator->shelfLifeStartDate(
            expiryDate: $expiryDate,
            shelfLife: $shelfLife,
            unit: $unit,
            calendar: $calendar,
        ));
    }

    public static function dateArithmeticCases(): iterable
    {
        yield 'seven days across a Gregorian year boundary' => [
            'gregorian', 'days', 7, '2025-12-29', '2026-01-05', '2025-12-29',
        ];
        yield 'seven Jalali calendar months' => [
            'jalali', 'months', 7, '1405-01-01', '1405-08-01', '1405-01-01',
        ];
        yield 'Jalali month-end clamp is not reversible' => [
            'jalali', 'months', 1, '1405-06-31', '1405-07-30', '1405-06-30',
        ];
        yield 'Gregorian common February clamps to day 28' => [
            'gregorian', 'months', 1, '2025-01-31', '2025-02-28', '2025-01-28',
        ];
        yield 'Gregorian leap February clamps to day 29' => [
            'gregorian', 'months', 1, '2024-01-31', '2024-02-29', '2024-01-29',
        ];
        yield 'Jalali common Esfand clamps to day 29' => [
            'jalali', 'months', 1, '1398-11-30', '1398-12-29', '1398-11-29',
        ];
        yield 'Jalali leap Esfand allows day 30' => [
            'jalali', 'months', 1, '1399-11-30', '1399-12-30', '1399-11-30',
        ];
        yield 'days cross a Jalali year boundary after leap Esfand' => [
            'jalali', 'days', 1, '1399-12-30', '1400-01-01', '1399-12-30',
        ];
        yield 'months are shifted in one operation' => [
            'gregorian', 'months', 2, '2025-01-31', '2025-03-31', '2025-01-31',
        ];
        yield 'first supported Jalali year' => [
            'jalali', 'days', 1, '0979-01-01', '0979-01-02', '0979-01-01',
        ];
        yield 'last supported Jalali year' => [
            'jalali', 'days', 1, '9999-06-31', '9999-07-01', '9999-06-31',
        ];
    }

    #[DataProvider('referenceDates')]
    public function testEvaluatesShelfLifeAtDateBoundaries(
        string $asOfDate,
        int $remainingDays,
        float $remainingPercentage,
        bool $expired,
    ): void {
        $result = (new ExpiryPricingCalculator())->remainingShelfLife(
            date: '2025-12-29',
            dateType: 'manufacturing',
            shelfLife: 7,
            unit: 'days',
            calendar: 'gregorian',
            asOfDate: $asOfDate,
        );

        self::assertSame('2025-12-29', $result['startDate']);
        self::assertSame('2026-01-05', $result['expiryDate']);
        self::assertFalse($result['startDateCalculated']);
        self::assertSame(7, $result['totalDays']);
        self::assertSame($remainingDays, $result['remainingDays']);
        self::assertEqualsWithDelta($remainingPercentage, $result['remainingPercentage'], 0.000000001);
        self::assertSame($expired, $result['expired']);
    }

    public static function referenceDates(): iterable
    {
        yield 'before manufacturing' => ['2025-12-28', 7, 100.0, false];
        yield 'at manufacturing' => ['2025-12-29', 7, 100.0, false];
        yield 'during shelf life' => ['2026-01-04', 1, 100 / 7, false];
        yield 'at expiry' => ['2026-01-05', 0, 0.0, true];
        yield 'after expiry' => ['2026-01-06', 0, 0.0, true];
    }

    #[DataProvider('jalaliDateOrigins')]
    public function testEvaluatesSevenJalaliMonthsFromEitherDate(
        string $date,
        string $dateType,
        bool $startDateCalculated,
    ): void {
        $result = (new ExpiryPricingCalculator())->remainingShelfLife(
            date: $date,
            dateType: $dateType,
            shelfLife: 7,
            unit: 'months',
            calendar: 'jalali',
            asOfDate: '1405-07-01',
        );

        self::assertSame('1405-01-01', $result['startDate']);
        self::assertSame('1405-08-01', $result['expiryDate']);
        self::assertSame($startDateCalculated, $result['startDateCalculated']);
        self::assertSame(216, $result['totalDays']);
        self::assertSame(30, $result['remainingDays']);
        self::assertEqualsWithDelta(30 / 216 * 100, $result['remainingPercentage'], 0.000000001);
        self::assertFalse($result['expired']);
    }

    public static function jalaliDateOrigins(): iterable
    {
        yield 'from manufacturing' => ['1405-01-01', 'manufacturing', false];
        yield 'from expiry' => ['1405-08-01', 'expiry', true];
    }

    public function testEvaluatesFromTheSuppliedExpiryWhenMonthsAreNotReversible(): void
    {
        $result = (new ExpiryPricingCalculator())->remainingShelfLife(
            date: '2025-03-31',
            dateType: 'expiry',
            shelfLife: 1,
            unit: 'months',
            calendar: 'gregorian',
            asOfDate: '2025-03-30',
        );

        self::assertSame('2025-02-28', $result['startDate']);
        self::assertSame('2025-03-31', $result['expiryDate']);
        self::assertTrue($result['startDateCalculated']);
        self::assertSame(31, $result['totalDays']);
        self::assertSame(1, $result['remainingDays']);
        self::assertFalse($result['expired']);
    }

    public function testCalendarDaysAreIndependentOfTheServerTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Apia');

        try {
            $result = (new ExpiryPricingCalculator())->remainingShelfLife(
                '2011-12-29', 'manufacturing', 2, 'days', 'gregorian', '2011-12-30',
            );

            self::assertSame('2011-12-31', $result['expiryDate']);
            self::assertSame(2, $result['totalDays']);
            self::assertSame(1, $result['remainingDays']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('invalidExpiryInputs')]
    public function testRejectsInvalidExpiryInputs(
        string $manufacturingDate,
        int $shelfLife,
        string $unit,
        string $calendar,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        (new ExpiryPricingCalculator())->expiryDate($manufacturingDate, $shelfLife, $unit, $calendar);
    }

    public static function invalidExpiryInputs(): iterable
    {
        yield 'unknown calendar' => ['2025-01-01', 7, 'days', 'persian'];
        yield 'unknown unit' => ['2025-01-01', 7, 'weeks', 'gregorian'];
        yield 'zero duration' => ['2025-01-01', 0, 'days', 'gregorian'];
        yield 'negative duration' => ['2025-01-01', -1, 'months', 'gregorian'];
        yield 'overflowing duration' => ['2025-01-01', PHP_INT_MAX, 'months', 'gregorian'];
        yield 'Gregorian February 30' => ['2024-02-30', 7, 'days', 'gregorian'];
        yield 'Gregorian non-leap February 29' => ['2025-02-29', 7, 'days', 'gregorian'];
        yield 'Jalali invalid second-half day' => ['1405-07-31', 7, 'days', 'jalali'];
        yield 'Jalali non-leap Esfand 30' => ['1398-12-30', 7, 'days', 'jalali'];
        yield 'non-padded date' => ['2025-1-01', 7, 'days', 'gregorian'];
        yield 'date contains a time' => ['2025-01-01T00:00:00Z', 7, 'days', 'gregorian'];
        yield 'Gregorian year zero' => ['0000-01-01', 7, 'days', 'gregorian'];
        yield 'Jalali year zero' => ['0000-01-01', 7, 'days', 'jalali'];
        yield 'Jalali year below supported range' => ['0978-12-29', 7, 'days', 'jalali'];
        yield 'result exceeds supported Jalali range' => ['9999-12-01', 1, 'months', 'jalali'];
        yield 'result exceeds supported Gregorian range' => ['9999-12-31', 1, 'days', 'gregorian'];
    }

    public function testRejectsShelfLifeStartBeforeTheSupportedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supported gregorian date range');

        (new ExpiryPricingCalculator())->shelfLifeStartDate('0001-01-01', 1, 'days', 'gregorian');
    }

    public function testRejectsAnUnsupportedDateType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dateType');

        (new ExpiryPricingCalculator())->remainingShelfLife(
            '2025-01-01', 'start', 7, 'days', 'gregorian', '2025-01-02',
        );
    }

    public function testRejectsAnInvalidReferenceDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('asOfDate');

        (new ExpiryPricingCalculator())->remainingShelfLife(
            '1405-06-31', 'manufacturing', 7, 'days', 'jalali', '1405-07-31',
        );
    }
}
