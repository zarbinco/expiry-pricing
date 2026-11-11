<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Support;

/** A table of calendar-day offsets with the first supported date at offset zero. */
final class CalendarOracle
{
    private const array GREGORIAN_MONTH_LENGTHS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    private const array JALALI_MONTH_LENGTHS = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    public const int LAST_YEAR = 9999;

    public readonly int $firstYear;
    /** @var array<int, int> */
    private array $yearStarts = [];

    public function __construct(private readonly string $calendar)
    {
        $this->firstYear = $calendar === 'gregorian' ? 1 : 979;
        $days = 0;
        for ($year = $this->firstYear; $year <= self::LAST_YEAR; $year++) {
            $this->yearStarts[$year] = $days;
            $days += $this->isLeapYear($year) ? 366 : 365;
        }
        $this->yearStarts[self::LAST_YEAR + 1] = $days;
    }

    private function isLeapYear(int $year): bool
    {
        if ($this->calendar === 'gregorian') {
            // https://aa.usno.navy.mil/faq/leap_years
            return $year % 400 === 0 || ($year % 4 === 0 && $year % 100 !== 0);
        }

        // Borkowski, section 1. Use the contract's fixed cycle throughout.
        // https://www.astro.uni.torun.pl/~kb/Papers/EMP/PersianC-EMP.htm
        return in_array($year % 33, [1, 5, 9, 13, 17, 22, 26, 30], true);
    }

    public function monthLength(int $year, int $month): int
    {
        [$lengths, $leapMonth] = $this->calendar === 'gregorian'
            ? [self::GREGORIAN_MONTH_LENGTHS, 2]
            : [self::JALALI_MONTH_LENGTHS, 12];

        return $lengths[$month - 1] + (int) ($month === $leapMonth && $this->isLeapYear($year));
    }

    public function ordinal(string $date): int
    {
        [$year, $month, $day] = self::parts($date);
        $ordinal = $this->yearStarts[$year] + $day - 1;
        for ($earlierMonth = 1; $earlierMonth < $month; $earlierMonth++) {
            $ordinal += $this->monthLength($year, $earlierMonth);
        }

        return $ordinal;
    }

    public function dateAt(int $ordinal): string
    {
        $first = $this->firstYear;
        $last = self::LAST_YEAR;
        while ($first < $last) {
            $middle = intdiv($first + $last + 1, 2);
            if ($this->yearStarts[$middle] > $ordinal) {
                $last = $middle - 1;
            } else {
                $first = $middle;
            }
        }

        $dayOfYear = $ordinal - $this->yearStarts[$first];
        $month = 1;
        while ($dayOfYear >= ($length = $this->monthLength($first, $month))) {
            $dayOfYear -= $length;
            $month++;
        }

        return sprintf('%04d-%02d-%02d', $first, $month, $dayOfYear + 1);
    }

    public function maximumOrdinal(): int
    {
        return $this->yearStarts[self::LAST_YEAR + 1] - 1;
    }

    public function shiftMonths(string $date, int $signedMonths): string
    {
        [$year, $month, $day] = self::parts($date);
        $direction = $signedMonths <=> 0;
        for ($step = 0; $step < abs($signedMonths); $step++) {
            $month += $direction;
            if ($month === 13) {
                $month = 1;
                $year++;
            } elseif ($month === 0) {
                $month = 12;
                $year--;
            }
        }

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $this->monthLength($year, $month)));
    }

    /** @return array{int, int, int} */
    private static function parts(string $date): array
    {
        return [(int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2)];
    }
}
