<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing;

use DateInterval;
use DateTimeImmutable;
use Hekmatinasser\Verta\Verta;
use InvalidArgumentException;

final class ExpiryPricingCalculator
{
    private const array FIRST_YEAR = ['gregorian' => 1, 'jalali' => 979];
    private const int MAX_YEAR = 9999;

    public function expiryDate(string $manufacturingDate, int $shelfLife, string $unit, string $calendar): string
    {
        $start = $this->parseDate($manufacturingDate, $calendar, 'manufacturingDate');
        $expiry = $this->shiftDate($start, $shelfLife, $unit, $calendar, backward: false);

        return $this->formatDate($expiry, $calendar);
    }

    public function shelfLifeStartDate(string $expiryDate, int $shelfLife, string $unit, string $calendar): string
    {
        $expiry = $this->parseDate($expiryDate, $calendar, 'expiryDate');
        $start = $this->shiftDate($expiry, $shelfLife, $unit, $calendar, backward: true);

        return $this->formatDate($start, $calendar);
    }

    /**
     * @return array{startDate: string, expiryDate: string, startDateCalculated: bool,
     *     totalDays: int, remainingDays: int, remainingPercentage: float, expired: bool}
     */
    public function remainingShelfLife(
        string $date,
        string $dateType,
        int $shelfLife,
        string $unit,
        string $calendar,
        string $asOfDate,
    ): array {
        $startDateCalculated = match ($dateType) {
            'manufacturing' => false,
            'expiry' => true,
            default => throw new InvalidArgumentException('dateType must be manufacturing or expiry.'),
        };

        $anchor = $this->parseDate($date, $calendar, 'date');
        $reference = $this->parseDate($asOfDate, $calendar, 'asOfDate');
        $shifted = $this->shiftDate($anchor, $shelfLife, $unit, $calendar, $startDateCalculated);
        [$start, $expiry] = $startDateCalculated ? [$shifted, $anchor] : [$anchor, $shifted];

        $totalDays = $start->diff($expiry)->days;
        $expired = $reference >= $expiry;
        $remainingDays = $expired ? 0 : min($totalDays, $reference->diff($expiry)->days);

        return [
            'startDate' => $this->formatDate($start, $calendar),
            'expiryDate' => $this->formatDate($expiry, $calendar),
            'startDateCalculated' => $startDateCalculated,
            'totalDays' => $totalDays,
            'remainingDays' => $remainingDays,
            'remainingPercentage' => ($remainingDays / $totalDays) * 100.0,
            'expired' => $expired,
        ];
    }

    private function parseDate(string $date, string $calendar, string $parameter): DateTimeImmutable
    {
        $firstYear = self::FIRST_YEAR[$calendar]
            ?? throw new InvalidArgumentException('calendar must be jalali or gregorian.');

        if (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $matches)) {
            throw new InvalidArgumentException("$parameter must use the YYYY-MM-DD format.");
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if ($year < $firstYear) {
            throw new InvalidArgumentException("$parameter is outside the supported $calendar date range.");
        }

        $valid = $calendar === 'jalali'
            ? Verta::isValidDate($year, $month, $day)
            : checkdate($month, $day, $year);
        if (!$valid) {
            throw new InvalidArgumentException("$parameter must be a valid $calendar date.");
        }

        return $this->utcDate($year, $month, $day, $calendar);
    }

    private function shiftDate(
        DateTimeImmutable $date,
        int $shelfLife,
        string $unit,
        string $calendar,
        bool $backward,
    ): DateTimeImmutable {
        if ($shelfLife <= 0) {
            throw new InvalidArgumentException('shelfLife must be a positive integer.');
        }

        return match ($unit) {
            'days' => $this->shiftDays($date, $shelfLife, $calendar, $backward),
            'months' => $this->shiftMonths($date, $shelfLife, $calendar, $backward),
            default => throw new InvalidArgumentException('unit must be days or months.'),
        };
    }

    private function shiftDays(DateTimeImmutable $date, int $days, string $calendar, bool $backward): DateTimeImmutable
    {
        $boundary = $backward
            ? $this->utcDate(self::FIRST_YEAR[$calendar], 1, 1, $calendar)
            : $this->utcDate(self::MAX_YEAR, 12, $this->lastDayOfMonth(self::MAX_YEAR, 12, $calendar), $calendar);

        if ($days > $date->diff($boundary)->days) {
            throw new InvalidArgumentException("shelfLife produces a date outside the supported $calendar date range.");
        }

        $interval = new DateInterval("P{$days}D");

        return $backward ? $date->sub($interval) : $date->add($interval);
    }

    private function shiftMonths(DateTimeImmutable $date, int $months, string $calendar, bool $backward): DateTimeImmutable
    {
        [$year, $month, $day] = $this->calendarParts($date, $calendar);
        $index = $year * 12 + $month - 1;
        $available = $backward ? $index - self::FIRST_YEAR[$calendar] * 12 : self::MAX_YEAR * 12 + 11 - $index;

        if ($months > $available) {
            throw new InvalidArgumentException("shelfLife produces a date outside the supported $calendar date range.");
        }

        $target = $backward ? $index - $months : $index + $months;
        $year = intdiv($target, 12);
        $month = $target % 12 + 1;
        $day = min($day, $this->lastDayOfMonth($year, $month, $calendar));

        return $this->utcDate($year, $month, $day, $calendar);
    }

    private function lastDayOfMonth(int $year, int $month, string $calendar): int
    {
        if ($calendar === 'gregorian') {
            return (int) $this->utcDate($year, $month, 1, $calendar)->format('t');
        }

        return match (true) {
            $month <= 6 => 31,
            $month < 12, Verta::isLeapYear($year) => 30,
            default => 29,
        };
    }

    private function utcDate(int $year, int $month, int $day, string $calendar): DateTimeImmutable
    {
        if ($calendar === 'jalali') {
            [$year, $month, $day] = Verta::jalaliToGregorian($year, $month, $day);
        }

        return (new DateTimeImmutable('0001-01-01T00:00:00+00:00'))->setDate($year, $month, $day);
    }

    /** @return array{int, int, int} */
    private function calendarParts(DateTimeImmutable $date, string $calendar): array
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date->format('Y-n-j')));

        return $calendar === 'jalali' ? Verta::GregorianToJalali($year, $month, $day) : [$year, $month, $day];
    }

    private function formatDate(DateTimeImmutable $date, string $calendar): string
    {
        return sprintf('%04d-%02d-%02d', ...$this->calendarParts($date, $calendar));
    }
}
