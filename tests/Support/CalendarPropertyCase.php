<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

require_once __DIR__ . '/CalendarOracle.php';

abstract class CalendarPropertyCase extends TestCase
{
    protected const int SAMPLE_SIZE = 96;
    protected const int SEED_OFFSET = 0;

    #[DataProvider('scenarios')]
    public function testGeneratedShiftsAndShelfLife(string $calendar, string $unit, int $seed): void
    {
        $oracle = new CalendarOracle($calendar);
        $random = new Randomizer(new Mt19937($seed));
        $calculator = new ExpiryPricingCalculator();

        for ($case = 0; $case < static::SAMPLE_SIZE; $case++) {
            $context = "seed=$seed case=$case calendar=$calendar unit=$unit";
            if ($unit === 'days') {
                $this->checkDayCase($calculator, $oracle, $random, $calendar, $case, $context);
            } else {
                $this->checkMonthCase($calculator, $oracle, $random, $calendar, $case, $context);
            }
        }
    }

    public static function scenarios(): iterable
    {
        yield 'Gregorian days' => ['gregorian', 'days', 17201 + static::SEED_OFFSET];
        yield 'Gregorian months' => ['gregorian', 'months', 17202 + static::SEED_OFFSET];
        yield 'Jalali days' => ['jalali', 'days', 17203 + static::SEED_OFFSET];
        yield 'Jalali months' => ['jalali', 'months', 17204 + static::SEED_OFFSET];
    }

    private function checkDayCase(
        ExpiryPricingCalculator $calculator,
        CalendarOracle $oracle,
        Randomizer $random,
        string $calendar,
        int $case,
        string $context,
    ): void {
        $lastDay = $oracle->maximumOrdinal();
        $startOrdinal = min($lastDay - 1, $oracle->ordinal(self::sampleDate($oracle, $random, $calendar, $case)));
        $available = $lastDay - $startOrdinal;
        $duration = match ($case % 17) {
            0 => $available,
            1 => $random->getInt(1, $available),
            default => $random->getInt(1, min($available, 1096)),
        };
        $start = $oracle->dateAt($startOrdinal);
        $expiry = $oracle->dateAt($startOrdinal + $duration);

        $this->checkShiftAndShelfLife($calculator, $oracle, $calendar, 'days', $duration, $start, $expiry, false, $context);
        $this->checkShiftAndShelfLife($calculator, $oracle, $calendar, 'days', $duration, $start, $expiry, true, $context);
    }

    private function checkMonthCase(
        ExpiryPricingCalculator $calculator,
        CalendarOracle $oracle,
        Randomizer $random,
        string $calendar,
        int $case,
        string $context,
    ): void {
        foreach ([false, true] as $backward) {
            $anchor = self::sampleDate($oracle, $random, $calendar, $case);
            $year = (int) substr($anchor, 0, 4);
            $month = (int) substr($anchor, 5, 2);
            $available = $backward
                ? 12 * ($year - $oracle->firstYear) + $month - 1
                : 12 * (CalendarOracle::LAST_YEAR - $year) + 12 - $month;
            if ($available === 0) {
                // Move one month into the supported range so the requested direction is valid.
                $anchor = $oracle->shiftMonths($anchor, $backward ? 1 : -1);
                $available = 1;
            }

            $duration = match ($case % 17) {
                0 => $available,
                1 => 1,
                2 => min($available, 12),
                3 => min($available, 2),
                default => $random->getInt(1, min($available, 1440)),
            };
            $shifted = $oracle->shiftMonths($anchor, $backward ? -$duration : $duration);
            [$start, $expiry] = $backward ? [$shifted, $anchor] : [$anchor, $shifted];

            $this->checkShiftAndShelfLife(
                $calculator, $oracle, $calendar, 'months', $duration, $start, $expiry, $backward, $context,
            );
        }
    }

    private static function sampleDate(CalendarOracle $oracle, Randomizer $random, string $calendar, int $case): string
    {
        $year = match ($case % 4) {
            0 => $random->getInt($oracle->firstYear, $oracle->firstYear + 4),
            1 => $random->getInt(CalendarOracle::LAST_YEAR - 4, CalendarOracle::LAST_YEAR),
            2 => $calendar === 'jalali' ? $random->getInt(1178, 1502) : $random->getInt(1700, 2401),
            3 => $random->getInt($oracle->firstYear, CalendarOracle::LAST_YEAR),
        };
        $month = $random->getInt(1, 12);
        $lastDay = $oracle->monthLength($year, $month);
        $day = match (intdiv($case, 4) % 4) {
            0 => 1,
            1 => $lastDay,
            2 => $lastDay - 1,
            3 => $random->getInt(1, $lastDay),
        };

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function checkShiftAndShelfLife(
        ExpiryPricingCalculator $calculator,
        CalendarOracle $oracle,
        string $calendar,
        string $unit,
        int $duration,
        string $start,
        string $expiry,
        bool $backward,
        string $context,
    ): void {
        $origin = $backward ? 'expiry' : 'manufacturing';
        [$anchor, $expectedShift] = $backward ? [$expiry, $start] : [$start, $expiry];
        $context .= " origin=$origin date=$anchor duration=$duration";
        try {
            $actualShift = $backward
                ? $calculator->shelfLifeStartDate($anchor, $duration, $unit, $calendar)
                : $calculator->expiryDate($anchor, $duration, $unit, $calendar);
        } catch (Throwable $error) {
            self::fail("$context expected date $expectedShift, got " . $error::class . ': ' . $error->getMessage());
        }
        self::assertSame($expectedShift, $actualShift, $context);

        $startOrdinal = $oracle->ordinal($start);
        $expiryOrdinal = $oracle->ordinal($expiry);
        $totalDays = $expiryOrdinal - $startOrdinal;
        $referenceOrdinals = array_unique([
            $startOrdinal - 1, $startOrdinal, $startOrdinal + 1, $startOrdinal + intdiv($totalDays, 2),
            $expiryOrdinal - 1, $expiryOrdinal, $expiryOrdinal + 1,
        ]);
        sort($referenceOrdinals, SORT_NUMERIC);

        $previous = $totalDays;
        foreach ($referenceOrdinals as $referenceOrdinal) {
            if ($referenceOrdinal < 0 || $referenceOrdinal > $oracle->maximumOrdinal()) {
                continue;
            }
            $asOf = $oracle->dateAt($referenceOrdinal);
            // Only the portion of the shelf-life interval on or after the reference day can remain.
            $expectedDays = max(0, $expiryOrdinal - max($startOrdinal, $referenceOrdinal));
            $expected = [
                'startDate' => $start,
                'expiryDate' => $expiry,
                'startDateCalculated' => $backward,
                'totalDays' => $totalDays,
                'remainingDays' => $expectedDays,
                'expired' => $referenceOrdinal >= $expiryOrdinal,
            ];
            $message = "$context asOfDate=$asOf";
            try {
                $actual = $calculator->remainingShelfLife($anchor, $origin, $duration, $unit, $calendar, $asOf);
            } catch (Throwable $error) {
                self::fail("$message expected " . json_encode($expected) . ', got ' . $error::class . ': ' . $error->getMessage());
            }

            $percentage = $actual['remainingPercentage'];
            unset($actual['remainingPercentage']);
            ksort($expected);
            ksort($actual);
            self::assertSame($expected, $actual, $message);
            self::assertIsFloat($percentage, $message);
            self::assertEqualsWithDelta(100.0 * $expectedDays / $totalDays, $percentage, 1e-9, $message);
            self::assertLessThanOrEqual($previous, $actual['remainingDays'], $message);
            $previous = $actual['remainingDays'];
        }
    }
}
