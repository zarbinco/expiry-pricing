<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Stress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;
use Zarbinco\ExpiryPricing\Tests\Support\CalendarOracle;

require_once __DIR__ . '/../Support/CalendarOracle.php';

final class CalendarSeamSweepTest extends TestCase
{
    #[DataProvider('calendars')]
    public function testAdjacentDatesAcrossEverySupportedMonthSeam(string $calendar): void
    {
        $calculator = new ExpiryPricingCalculator();
        $oracle = new CalendarOracle($calendar);

        for ($year = $oracle->firstYear; $year <= 9999; ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                if ($year === 9999 && $month === 12) {
                    break; // The next month is outside the public range.
                }

                $last = sprintf('%04d-%02d-%02d', $year, $month, $oracle->monthLength($year, $month));
                $next = $month === 12
                    ? sprintf('%04d-01-01', $year + 1)
                    : sprintf('%04d-%02d-01', $year, $month + 1);

                self::assertSame(
                    $next,
                    $calculator->expiryDate($last, 1, 'days', $calendar),
                    "$calendar: one day forward from $last",
                );
                self::assertSame(
                    $last,
                    $calculator->shelfLifeStartDate($next, 1, 'days', $calendar),
                    "$calendar: one day backward from $next",
                );
            }
        }
    }

    public static function calendars(): iterable
    {
        yield 'Gregorian' => ['gregorian'];
        yield 'Jalali' => ['jalali'];
    }
}
