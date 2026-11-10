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
    private const int BPS_PER_WHOLE = 10000;

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

    /**
     * @param int $shelfLife
     * @param int $originalPrice Price in minor units.
     * @param array<array{remainingThresholdBps: int, discountBps: int}> $rules
     * @param int $minimumPriceRatioBps
     * @param int $roundingStep
     * @return array{originalPrice: int, finalPrice: int, discountBps: int, effectiveDiscountBps: int,
     *     matchedRule: array{remainingThresholdBps: int, discountBps: int}|null,
     *     priceFloorApplied: bool, roundingApplied: bool, shelfLife: array{startDate: string, expiryDate: string,
     *         startDateCalculated: bool, totalDays: int, remainingDays: int, remainingPercentage: float, expired: bool}}
     */
    public function price(
        string $date,
        string $dateType,
        mixed $shelfLife,
        string $unit,
        string $calendar,
        string $asOfDate,
        mixed $originalPrice,
        mixed $rules,
        mixed $minimumPriceRatioBps,
        mixed $roundingStep,
    ): array {
        if (!is_int($shelfLife)) {
            throw new InvalidArgumentException('shelfLife must be a positive integer.');
        }

        $maximumPrice = intdiv(PHP_INT_MAX, self::BPS_PER_WHOLE);
        if (!is_int($originalPrice) || $originalPrice < 0 || $originalPrice > $maximumPrice) {
            throw new InvalidArgumentException("originalPrice must be an integer between 0 and $maximumPrice minor units.");
        }

        if (!is_array($rules)) {
            throw new InvalidArgumentException('rules must be an array of discount rules.');
        }

        $minimumPriceRatioBps = $this->validateBps($minimumPriceRatioBps, 'minimumPriceRatioBps');
        if (!is_int($roundingStep) || $roundingStep <= 0) {
            throw new InvalidArgumentException('roundingStep must be a positive integer.');
        }

        $life = $this->remainingShelfLife($date, $dateType, $shelfLife, $unit, $calendar, $asOfDate);
        $matchedRule = $this->selectDiscountRule($rules, $life['totalDays'], $life['remainingDays'], $life['expired']);
        $discountBps = $matchedRule['discountBps'] ?? 0;
        $finalPrice = $originalPrice;
        $roundingApplied = false;
        $priceFloorApplied = false;

        if ($discountBps > 0) {
            $discountedNumerator = $originalPrice * (self::BPS_PER_WHOLE - $discountBps);
            $minorPrice = $this->halfUpDivide($discountedNumerator, self::BPS_PER_WHOLE);
            // HALF_UP adds at most the remainder, so the rounded multiple is at most twice $minorPrice.
            $stepPrice = $this->halfUpDivide($minorPrice, $roundingStep) * $roundingStep;
            $floorPrice = $this->ceilBps($originalPrice, $minimumPriceRatioBps);

            $roundingApplied = $discountedNumerator % self::BPS_PER_WHOLE !== 0 || $minorPrice !== $stepPrice;
            $priceFloorApplied = $stepPrice < $floorPrice;
            $finalPrice = min($originalPrice, max($stepPrice, $floorPrice));
        }

        return [
            'originalPrice' => $originalPrice,
            'finalPrice' => $finalPrice,
            'discountBps' => $discountBps,
            'effectiveDiscountBps' => $originalPrice === 0
                ? 0
                : $this->halfUpDivide(($originalPrice - $finalPrice) * self::BPS_PER_WHOLE, $originalPrice),
            'matchedRule' => $matchedRule,
            'priceFloorApplied' => $priceFloorApplied,
            'roundingApplied' => $roundingApplied,
            'shelfLife' => $life,
        ];
    }

    /**
     * @param int $shelfLife
     * @param int $originalPrice Price in minor units.
     * @param array<array{remainingThresholdBps: int, discountBps: int}> $rules
     * @param int $minimumPriceRatioBps
     * @param int $roundingStep
     * @return array{currentQuote: array, expiryDate: string, transitions: list<array{
     *     effectiveDate: string, remainingDays: int, discountBps: int, finalPrice: int,
     *     matchedRule: array{remainingThresholdBps: int, discountBps: int}|null}>}
     */
    public function discountTimeline(
        string $date,
        string $dateType,
        mixed $shelfLife,
        string $unit,
        string $calendar,
        string $asOfDate,
        mixed $originalPrice,
        mixed $rules,
        mixed $minimumPriceRatioBps,
        mixed $roundingStep,
    ): array {
        $currentQuote = $this->price(
            $date, $dateType, $shelfLife, $unit, $calendar, $asOfDate,
            $originalPrice, $rules, $minimumPriceRatioBps, $roundingStep,
        );
        $life = $currentQuote['shelfLife'];
        $expiryDate = $life['expiryDate'];
        $cutoffs = [];

        foreach ($rules as $rule) {
            $cutoff = $this->ceilBps($life['totalDays'], $rule['remainingThresholdBps']);
            // Zero is expiry. A cutoff already reached cannot change the current quote.
            if ($cutoff > 0 && $cutoff < $life['remainingDays']) {
                $cutoffs[$cutoff] = true;
            }
        }

        krsort($cutoffs, SORT_NUMERIC);
        $transitions = [];
        $lastDiscountBps = $currentQuote['discountBps'];

        foreach (array_keys($cutoffs) as $cutoff) {
            $effectiveDate = $this->shelfLifeStartDate($expiryDate, $cutoff, 'days', $calendar);
            $quote = $this->price(
                $date, $dateType, $shelfLife, $unit, $calendar, $effectiveDate,
                $originalPrice, $rules, $minimumPriceRatioBps, $roundingStep,
            );

            if ($quote['discountBps'] === $lastDiscountBps) {
                continue;
            }

            $lastDiscountBps = $quote['discountBps'];
            $transitions[] = [
                'effectiveDate' => $effectiveDate,
                'remainingDays' => $quote['shelfLife']['remainingDays'],
                'discountBps' => $lastDiscountBps,
                'finalPrice' => $quote['finalPrice'],
                'matchedRule' => $quote['matchedRule'],
            ];
        }

        return [
            'currentQuote' => $currentQuote,
            'expiryDate' => $expiryDate,
            'transitions' => $transitions,
        ];
    }

    /** @return array{remainingThresholdBps: int, discountBps: int}|null */
    private function selectDiscountRule(array $rules, int $totalDays, int $remainingDays, bool $expired): ?array
    {
        $matched = null;
        foreach ($rules as $key => $rule) {
            if (!is_array($rule) || count($rule) !== 2) {
                throw new InvalidArgumentException("rules[$key] must contain remainingThresholdBps and discountBps.");
            }

            $thresholdBps = $this->validateBps($rule['remainingThresholdBps'] ?? null, "rules[$key].remainingThresholdBps");
            $discountBps = $this->validateBps($rule['discountBps'] ?? null, "rules[$key].discountBps");
            if ($expired || $remainingDays > $this->ceilBps($totalDays, $thresholdBps)) {
                continue;
            }

            if ($matched === null || $discountBps > $matched['discountBps']
                || ($discountBps === $matched['discountBps'] && $thresholdBps < $matched['remainingThresholdBps'])) {
                $matched = ['remainingThresholdBps' => $thresholdBps, 'discountBps' => $discountBps];
            }
        }

        return $matched;
    }

    private function validateBps(mixed $value, string $parameter): int
    {
        if (!is_int($value) || $value < 0 || $value > self::BPS_PER_WHOLE) {
            throw new InvalidArgumentException("$parameter must be an integer between 0 and 10000 basis points.");
        }

        return $value;
    }

    private function ceilBps(int $value, int $bps): int
    {
        // Split off whole groups of 10,000 to keep the intermediate products in range.
        $whole = intdiv($value, self::BPS_PER_WHOLE) * $bps;
        $remainder = ($value % self::BPS_PER_WHOLE) * $bps;

        return $whole + intdiv($remainder + self::BPS_PER_WHOLE - 1, self::BPS_PER_WHOLE);
    }

    private function halfUpDivide(int $numerator, int $denominator): int
    {
        $remainder = $numerator % $denominator;

        return intdiv($numerator, $denominator) + ($remainder >= $denominator - $remainder ? 1 : 0);
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
