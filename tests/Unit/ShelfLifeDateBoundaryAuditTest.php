<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class ShelfLifeDateBoundaryAuditTest extends TestCase
{
    #[DataProvider('shelfLifeRanges')]
    public function testShelfLifeFieldsAcrossTheWholeInterval(
        string $calendar,
        string $dateType,
        int $duration,
        string $unit,
        string $start,
        string $expiry,
        int $totalDays,
        array $remainingByDate,
    ): void {
        $calculator = new ExpiryPricingCalculator();
        $fromExpiry = $dateType === 'expiry';
        $date = $fromExpiry ? $expiry : $start;
        if ($fromExpiry) {
            self::assertSame($start, $calculator->shelfLifeStartDate($expiry, $duration, $unit, $calendar));
        }

        $previousRemaining = $totalDays;
        foreach ($remainingByDate as $asOfDate => $remainingDays) {
            $life = $calculator->remainingShelfLife($date, $dateType, $duration, $unit, $calendar, $asOfDate);

            self::assertSame($start, $life['startDate'], $asOfDate);
            self::assertSame($expiry, $life['expiryDate'], $asOfDate);
            self::assertSame($fromExpiry, $life['startDateCalculated'], $asOfDate);
            self::assertSame($totalDays, $life['totalDays'], $asOfDate);
            self::assertGreaterThan(0, $life['totalDays'], $asOfDate);
            self::assertSame($remainingDays, $life['remainingDays'], $asOfDate);
            self::assertGreaterThanOrEqual(0, $life['remainingDays'], $asOfDate);
            self::assertLessThanOrEqual($previousRemaining, $life['remainingDays'], $asOfDate);
            self::assertIsFloat($life['remainingPercentage'], $asOfDate);
            self::assertEqualsWithDelta(100 * $remainingDays / $totalDays, $life['remainingPercentage'], 1e-9, $asOfDate);
            self::assertSame($asOfDate >= $expiry, $life['expired'], $asOfDate);
            $previousRemaining = $life['remainingDays'];
        }
    }

    public static function shelfLifeRanges(): iterable
    {
        // Expected distances are counted over the calendar month lengths in each fixture.
        // Esfand 1399 has 30 days: 1399-12-30 = 2021-03-20, 1400-01-01 = 2021-03-21.
        foreach (['manufacturing', 'expiry'] as $origin) {
            yield "Gregorian leap day from $origin" => [
                'gregorian', $origin, 7, 'days', '2000-02-26', '2000-03-04', 7,
                [
                    '2000-02-25' => 7, '2000-02-26' => 7, '2000-02-27' => 6,
                    '2000-02-29' => 4, '2000-03-02' => 2, '2000-03-03' => 1,
                    '2000-03-04' => 0, '2000-03-05' => 0, '2100-01-01' => 0,
                ],
            ];
            yield "Jalali leap day from $origin" => [
                'jalali', $origin, 7, 'days', '1399-12-27', '1400-01-04', 7,
                [
                    '1399-12-26' => 7, '1399-12-27' => 7, '1399-12-28' => 6,
                    '1399-12-30' => 4, '1400-01-02' => 2, '1400-01-03' => 1,
                    '1400-01-04' => 0, '1400-01-05' => 0, '1405-01-01' => 0,
                ],
            ];
        }

        // Each pair shares the expiry and some reference dates but has a different start.
        yield 'Gregorian month clamp from manufacture' => [
            'gregorian', 'manufacturing', 1, 'months', '2025-01-31', '2025-02-28', 28,
            [
                '2025-01-30' => 28, '2025-01-31' => 28, '2025-02-01' => 27,
                '2025-02-14' => 14, '2025-02-26' => 2, '2025-02-27' => 1,
                '2025-02-28' => 0, '2025-03-01' => 0, '2026-02-28' => 0,
            ],
        ];
        yield 'Gregorian month from the resulting expiry' => [
            'gregorian', 'expiry', 1, 'months', '2025-01-28', '2025-02-28', 31,
            [
                '2025-01-27' => 31, '2025-01-28' => 31, '2025-01-29' => 30,
                '2025-02-14' => 14, '2025-02-26' => 2, '2025-02-27' => 1,
                '2025-02-28' => 0, '2025-03-01' => 0, '2026-02-28' => 0,
            ],
        ];
        yield 'Jalali month clamp from manufacture' => [
            'jalali', 'manufacturing', 1, 'months', '1405-06-31', '1405-07-30', 30,
            [
                '1405-06-30' => 30, '1405-06-31' => 30, '1405-07-01' => 29,
                '1405-07-15' => 15, '1405-07-28' => 2, '1405-07-29' => 1,
                '1405-07-30' => 0, '1405-08-01' => 0, '1406-07-30' => 0,
            ],
        ];
        yield 'Jalali month from the resulting expiry' => [
            'jalali', 'expiry', 1, 'months', '1405-06-30', '1405-07-30', 31,
            [
                '1405-06-29' => 31, '1405-06-30' => 31, '1405-06-31' => 30,
                '1405-07-15' => 15, '1405-07-28' => 2, '1405-07-29' => 1,
                '1405-07-30' => 0, '1405-08-01' => 0, '1406-07-30' => 0,
            ],
        ];

        // Adding a month to either derived start would lose the supplied day 31.
        yield 'Gregorian expiry-only clamp into leap February' => [
            'gregorian', 'expiry', 1, 'months', '2000-02-29', '2000-03-31', 31,
            [
                '2000-02-28' => 31, '2000-02-29' => 31, '2000-03-01' => 30,
                '2000-03-15' => 16, '2000-03-29' => 2, '2000-03-30' => 1,
                '2000-03-31' => 0, '2000-04-01' => 0, '2001-03-31' => 0,
            ],
        ];
        yield 'Jalali expiry-only clamp into leap Esfand' => [
            'jalali', 'expiry', 1, 'months', '1399-12-30', '1400-01-31', 31,
            [
                '1399-12-29' => 31, '1399-12-30' => 31, '1400-01-01' => 30,
                '1400-01-15' => 16, '1400-01-29' => 2, '1400-01-30' => 1,
                '1400-01-31' => 0, '1400-02-01' => 0, '1401-01-31' => 0,
            ],
        ];
    }
}
