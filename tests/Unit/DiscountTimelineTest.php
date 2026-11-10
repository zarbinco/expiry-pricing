<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class DiscountTimelineTest extends TestCase
{
    private const array RULES = [
        ['remainingThresholdBps' => 3000, 'discountBps' => 2000],
        ['remainingThresholdBps' => 1000, 'discountBps' => 4000],
    ];

    #[DataProvider('sevenDayReferenceDates')]
    public function testReturnsOnlyFutureTransitionsAndKeepsExpirySeparate(string $asOfDate, int $firstFutureIndex): void
    {
        $calculator = new ExpiryPricingCalculator();
        $inputs = [
            'date' => '1405-01-01',
            'dateType' => 'manufacturing',
            'shelfLife' => 7,
            'unit' => 'days',
            'calendar' => 'jalali',
            'asOfDate' => $asOfDate,
            'originalPrice' => 100000,
            'rules' => self::RULES,
            'minimumPriceRatioBps' => 0,
            'roundingStep' => 1,
        ];
        $expectedTransitions = [
            [
                'effectiveDate' => '1405-01-05',
                'remainingDays' => 3,
                'discountBps' => 2000,
                'finalPrice' => 80000,
                'matchedRule' => self::RULES[0],
            ],
            [
                'effectiveDate' => '1405-01-07',
                'remainingDays' => 1,
                'discountBps' => 4000,
                'finalPrice' => 60000,
                'matchedRule' => self::RULES[1],
            ],
        ];

        $timeline = $calculator->discountTimeline(...$inputs);

        self::assertSame($calculator->price(...$inputs), $timeline['currentQuote']);
        self::assertSame('1405-01-08', $timeline['expiryDate']);
        self::assertSame(array_slice($expectedTransitions, $firstFutureIndex), $timeline['transitions']);
    }

    public static function sevenDayReferenceDates(): iterable
    {
        yield 'before manufacture' => ['1404-12-28', 0];
        yield 'at manufacture' => ['1405-01-01', 0];
        yield 'at the first transition' => ['1405-01-05', 1];
        yield 'after the first transition' => ['1405-01-06', 1];
        yield 'at the final transition' => ['1405-01-07', 2];
        yield 'at expiry' => ['1405-01-08', 2];
        yield 'after expiry' => ['1405-01-09', 2];
    }

    #[DataProvider('calendarMonthInputs')]
    public function testCalculatesTransitionsFromActualCalendarMonthEndpoints(
        string $date,
        string $dateType,
        string $calendar,
        int $months,
        string $startDate,
        string $expiryDate,
        int $totalDays,
        array $expectedDates,
        array $expectedRemainingDays,
    ): void {
        $timeline = (new ExpiryPricingCalculator())->discountTimeline(
            $date, $dateType, $months, 'months', $calendar, $startDate, 100000, self::RULES, 0, 1,
        );

        self::assertSame($startDate, $timeline['currentQuote']['shelfLife']['startDate']);
        self::assertSame($expiryDate, $timeline['expiryDate']);
        self::assertSame($totalDays, $timeline['currentQuote']['shelfLife']['totalDays']);
        self::assertSame($dateType === 'expiry', $timeline['currentQuote']['shelfLife']['startDateCalculated']);
        self::assertSame($expectedDates, array_column($timeline['transitions'], 'effectiveDate'));
        self::assertSame($expectedRemainingDays, array_column($timeline['transitions'], 'remainingDays'));
        self::assertSame([2000, 4000], array_column($timeline['transitions'], 'discountBps'));
    }

    public static function calendarMonthInputs(): iterable
    {
        yield 'seven Jalali months from manufacture' => [
            '1405-01-01', 'manufacturing', 'jalali', 7, '1405-01-01', '1405-08-01', 216,
            ['1405-05-28', '1405-07-09'], [65, 22],
        ];
        yield 'seven Jalali months from expiry' => [
            '1405-08-01', 'expiry', 'jalali', 7, '1405-01-01', '1405-08-01', 216,
            ['1405-05-28', '1405-07-09'], [65, 22],
        ];
        yield 'Gregorian month-end clamp from manufacture' => [
            '2025-01-31', 'manufacturing', 'gregorian', 1, '2025-01-31', '2025-02-28', 28,
            ['2025-02-19', '2025-02-25'], [9, 3],
        ];
        yield 'Gregorian month-end inverse from expiry' => [
            '2025-02-28', 'expiry', 'gregorian', 1, '2025-01-28', '2025-02-28', 31,
            ['2025-02-18', '2025-02-24'], [10, 4],
        ];
    }

    public function testCombinesCoincidentThresholdsIndependentlyOfRuleOrder(): void
    {
        $calculator = new ExpiryPricingCalculator();
        $rules = [...self::RULES, ['remainingThresholdBps' => 2000, 'discountBps' => 4000], self::RULES[1]];
        $timeline = $calculator->discountTimeline(
            '2026-01-01', 'manufacturing', 2, 'days', 'gregorian', '2026-01-01', 100000, $rules, 0, 1,
        );
        $reversed = $calculator->discountTimeline(
            '2026-01-01', 'manufacturing', 2, 'days', 'gregorian', '2026-01-01', 100000, array_reverse($rules), 0, 1,
        );

        self::assertSame($timeline, $reversed);
        self::assertSame([[
            'effectiveDate' => '2026-01-02',
            'remainingDays' => 1,
            'discountBps' => 4000,
            'finalPrice' => 60000,
            'matchedRule' => self::RULES[1],
        ]], $timeline['transitions']);
    }

    public function testSkipsAnEqualDiscountWhenTheMatchedRuleChanges(): void
    {
        $equalDiscount = ['remainingThresholdBps' => 2000, 'discountBps' => 2000];
        $timeline = (new ExpiryPricingCalculator())->discountTimeline(
            '1405-01-01', 'manufacturing', 7, 'days', 'jalali', '1405-01-01',
            100000, [...self::RULES, $equalDiscount], 0, 1,
        );

        self::assertSame(['1405-01-05', '1405-01-07'], array_column($timeline['transitions'], 'effectiveDate'));
        self::assertSame(self::RULES, array_column($timeline['transitions'], 'matchedRule'));
    }

    #[DataProvider('casesWithoutFutureChanges')]
    public function testReturnsAnEmptyTimelineWithoutFutureNominalChanges(int $shelfLife, array $rules, int $currentBps): void
    {
        $timeline = (new ExpiryPricingCalculator())->discountTimeline(
            '2026-01-01', 'manufacturing', $shelfLife, 'days', 'gregorian', '2025-12-31', 100000, $rules, 0, 1,
        );

        self::assertSame($shelfLife, $timeline['currentQuote']['shelfLife']['remainingDays']);
        self::assertSame($currentBps, $timeline['currentQuote']['discountBps']);
        self::assertSame([], $timeline['transitions']);
    }

    public static function casesWithoutFutureChanges(): iterable
    {
        yield 'empty rules' => [7, [], 0];
        yield 'zero discount' => [7, [['remainingThresholdBps' => 3000, 'discountBps' => 0]], 0];
        yield 'zero threshold' => [7, [['remainingThresholdBps' => 0, 'discountBps' => 4000]], 0];
        yield 'full threshold already qualifies before manufacture' => [
            7, [['remainingThresholdBps' => 10000, 'discountBps' => 2000]], 2000,
        ];
        yield 'all positive thresholds already qualify for a one-day product' => [1, self::RULES, 4000];
    }

    #[DataProvider('unchangedFinalPrices')]
    public function testKeepsNominalTransitionsWhenFinalPriceStaysTheSame(int $price, int $floor, int $step): void
    {
        $timeline = (new ExpiryPricingCalculator())->discountTimeline(
            '1405-01-01', 'manufacturing', 7, 'days', 'jalali', '1405-01-01', $price, self::RULES, $floor, $step,
        );

        self::assertSame($price, $timeline['currentQuote']['finalPrice']);
        self::assertSame([2000, 4000], array_column($timeline['transitions'], 'discountBps'));
        self::assertSame([$price, $price], array_column($timeline['transitions'], 'finalPrice'));
    }

    public static function unchangedFinalPrices(): iterable
    {
        yield 'price floor' => [100000, 10000, 1];
        yield 'monetary step' => [100, 0, 100];
    }

    #[DataProvider('largeRoundingSteps')]
    public function testRoundsTransitionPricesToLargeSteps(int $roundingStep, array $expectedPrices): void
    {
        $timeline = (new ExpiryPricingCalculator())->discountTimeline(
            '1405-01-01', 'manufacturing', 7, 'days', 'jalali', '1405-01-01',
            180000, self::RULES, 0, $roundingStep,
        );

        self::assertSame([2000, 4000], array_column($timeline['transitions'], 'discountBps'));
        self::assertSame($expectedPrices, array_column($timeline['transitions'], 'finalPrice'));
    }

    public static function largeRoundingSteps(): iterable
    {
        yield 'step of ten thousand' => [10000, [140000, 110000]];
        yield 'step of one hundred thousand' => [100000, [100000, 100000]];
    }

    public function testCalculatesAThresholdAcrossTheFullGregorianDateRange(): void
    {
        $timeline = (new ExpiryPricingCalculator())->discountTimeline(
            '0001-01-01', 'manufacturing', 3652058, 'days', 'gregorian', '0001-01-01',
            100000, [['remainingThresholdBps' => 1, 'discountBps' => 2000]], 0, 1,
        );

        self::assertSame('9999-12-31', $timeline['expiryDate']);
        self::assertSame(['9998-12-30'], array_column($timeline['transitions'], 'effectiveDate'));
        self::assertSame([366], array_column($timeline['transitions'], 'remainingDays'));
    }

    public function testValidatesPricingRulesEvenAtExpiry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExpiryPricingCalculator())->discountTimeline(
            '2026-01-01', 'manufacturing', 7, 'days', 'gregorian', '2026-01-08',
            100000, [['remainingThresholdBps' => -1, 'discountBps' => 2000]], 0, 1,
        );
    }
}
