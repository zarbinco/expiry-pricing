<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;

final class ExpiryPricingTest extends TestCase
{
    private const array RULES = [
        ['remainingThresholdBps' => 3000, 'discountBps' => 2000],
        ['remainingThresholdBps' => 1000, 'discountBps' => 4000],
    ];

    #[DataProvider('sevenDayQuotes')]
    public function testQuotesASevenDayJalaliProduct(
        string $asOfDate,
        int $remainingDays,
        ?int $ruleIndex,
        int $finalPrice,
    ): void {
        $quote = (new ExpiryPricingCalculator())->price(
            date: '1405-01-01',
            dateType: 'manufacturing',
            shelfLife: 7,
            unit: 'days',
            calendar: 'jalali',
            asOfDate: $asOfDate,
            originalPrice: 100000,
            rules: self::RULES,
            minimumPriceRatioBps: 0,
            roundingStep: 1,
        );

        $expectedRule = $ruleIndex === null ? null : self::RULES[$ruleIndex];
        self::assertSame($expectedRule, $quote['matchedRule']);
        self::assertSame($expectedRule['discountBps'] ?? 0, $quote['discountBps']);
        self::assertSame(100000, $quote['originalPrice']);
        self::assertSame($finalPrice, $quote['finalPrice']);
        self::assertSame(7, $quote['shelfLife']['totalDays']);
        self::assertSame($remainingDays, $quote['shelfLife']['remainingDays']);
        self::assertEqualsWithDelta($remainingDays / 7 * 100, $quote['shelfLife']['remainingPercentage'], 1e-9);
    }

    public static function sevenDayQuotes(): iterable
    {
        yield 'at manufacture' => ['1405-01-01', 7, null, 100000];
        yield 'above 30 percent cutoff' => ['1405-01-04', 4, null, 100000];
        yield 'at 30 percent cutoff' => ['1405-01-05', 3, 0, 80000];
        yield 'above 10 percent cutoff' => ['1405-01-06', 2, 0, 80000];
        yield 'at 10 percent cutoff, both rules qualify' => ['1405-01-07', 1, 1, 60000];
    }

    #[DataProvider('sevenMonthQuotes')]
    public function testCeilCutoffsForA216DayExpiryBasedJalaliProduct(
        string $asOfDate,
        int $remainingDays,
        int $discountBps,
    ): void {
        $quote = (new ExpiryPricingCalculator())->price(
            date: '1405-08-01',
            dateType: 'expiry',
            shelfLife: 7,
            unit: 'months',
            calendar: 'jalali',
            asOfDate: $asOfDate,
            originalPrice: 100000,
            rules: self::RULES,
            minimumPriceRatioBps: 0,
            roundingStep: 1,
        );

        self::assertSame('1405-01-01', $quote['shelfLife']['startDate']);
        self::assertTrue($quote['shelfLife']['startDateCalculated']);
        self::assertSame(216, $quote['shelfLife']['totalDays']);
        self::assertSame($remainingDays, $quote['shelfLife']['remainingDays']);
        self::assertSame($discountBps, $quote['discountBps']);
    }

    public static function sevenMonthQuotes(): iterable
    {
        yield 'above 30 percent cutoff' => ['1405-05-27', 66, 0];
        yield 'at 30 percent cutoff' => ['1405-05-28', 65, 2000];
        yield 'above 10 percent cutoff' => ['1405-07-08', 23, 2000];
        yield 'at 10 percent cutoff' => ['1405-07-09', 22, 4000];
    }

    public function testExactWholeDayCutoffDoesNotAdvanceByADay(): void
    {
        $calculator = new ExpiryPricingCalculator();
        $fourDaysLeft = $calculator->price(
            '2026-01-01', 'manufacturing', 10, 'days', 'gregorian', '2026-01-07', 100000, self::RULES, 0, 1,
        );
        $threeDaysLeft = $calculator->price(
            '2026-01-01', 'manufacturing', 10, 'days', 'gregorian', '2026-01-08', 100000, self::RULES, 0, 1,
        );

        self::assertSame(0, $fourDaysLeft['discountBps']);
        self::assertSame(2000, $threeDaysLeft['discountBps']);
    }

    #[DataProvider('rulePriorities')]
    public function testRuleSelectionIsIndependentOfOrder(array $firstRule, array $secondRule, array $winner): void
    {
        $calculator = new ExpiryPricingCalculator();
        $quote = $calculator->price('2026-01-01', 'manufacturing', 2, 'days', 'gregorian', '2026-01-02',
            100000, [$firstRule, $secondRule], 0, 1);
        $reversed = $calculator->price('2026-01-01', 'manufacturing', 2, 'days', 'gregorian', '2026-01-02',
            100000, [$secondRule, $firstRule], 0, 1);

        self::assertSame($winner, $quote['matchedRule']);
        self::assertSame($quote, $reversed);
    }

    public static function rulePriorities(): iterable
    {
        $largerThreshold = ['remainingThresholdBps' => 3000, 'discountBps' => 2000];
        $smallerThreshold = ['remainingThresholdBps' => 1000, 'discountBps' => 2000];
        $largerDiscount = ['remainingThresholdBps' => 4000, 'discountBps' => 3000];

        // All rules in these pairs have the same one-day cutoff.
        yield 'larger discount wins' => [$largerThreshold, $largerDiscount, $largerDiscount];
        yield 'smaller threshold breaks equal discounts' => [$largerThreshold, $smallerThreshold, $smallerThreshold];
    }

    #[DataProvider('quotesWithoutADiscount')]
    public function testLeavesOriginalPriceWhenNoDiscountApplies(string $asOfDate, array $rules, bool $expired): void
    {
        $quote = (new ExpiryPricingCalculator())->price('2026-01-01', 'manufacturing', 2, 'days', 'gregorian',
            $asOfDate, 1550, $rules, 10000, 1000);

        self::assertSame($expired, $quote['shelfLife']['expired']);
        self::assertNull($quote['matchedRule']);
        self::assertSame(0, $quote['discountBps']);
        self::assertSame(0, $quote['effectiveDiscountBps']);
        self::assertSame(1550, $quote['finalPrice']);
        self::assertFalse($quote['roundingApplied']);
        self::assertFalse($quote['priceFloorApplied']);
    }

    public static function quotesWithoutADiscount(): iterable
    {
        yield 'empty rules' => ['2026-01-02', [], false];
        yield 'zero threshold never qualifies before expiry' => [
            '2026-01-02', [['remainingThresholdBps' => 0, 'discountBps' => 10000]], false,
        ];
        yield 'on expiry' => ['2026-01-03', self::RULES, true];
        yield 'after expiry' => ['2026-01-04', self::RULES, true];
    }

    #[DataProvider('moneyCases')]
    public function testCalculatesPriceInMinorUnits(
        int $originalPrice,
        int $discountBps,
        int $minimumPriceRatioBps,
        int $roundingStep,
        int $finalPrice,
        int $effectiveDiscountBps,
        bool $roundingApplied,
        bool $priceFloorApplied,
    ): void {
        if ($originalPrice > intdiv(PHP_INT_MAX, 10000)) {
            self::markTestSkipped('The example price exceeds the integer price limit on this platform.');
        }

        $rule = ['remainingThresholdBps' => 10000, 'discountBps' => $discountBps];
        $quote = (new ExpiryPricingCalculator())->price('2026-01-01', 'manufacturing', 2, 'days', 'gregorian',
            '2026-01-01', $originalPrice, [$rule], $minimumPriceRatioBps, $roundingStep);

        self::assertSame($rule, $quote['matchedRule']);
        self::assertSame($discountBps, $quote['discountBps']);
        self::assertSame($finalPrice, $quote['finalPrice']);
        self::assertSame($effectiveDiscountBps, $quote['effectiveDiscountBps']);
        self::assertSame($roundingApplied, $quote['roundingApplied']);
        self::assertSame($priceFloorApplied, $quote['priceFloorApplied']);
    }

    public static function moneyCases(): iterable
    {
        yield '25 percent discount with step 1' => [250000, 2500, 0, 1, 187500, 2500, false, false];
        yield '25 percent discount with step 10' => [250000, 2500, 0, 10, 187500, 2500, false, false];
        yield '25 percent discount with step 100' => [250000, 2500, 0, 100, 187500, 2500, false, false];
        yield '25 percent discount with step 1000' => [250000, 2500, 0, 1000, 188000, 2480, true, false];
        yield '25 percent discount with step 10000' => [250000, 2500, 0, 10000, 190000, 2400, true, false];
        yield '25 percent discount with step 100000' => [250000, 2500, 0, 100000, 200000, 2000, true, false];
        yield 'step 3000 rounds a tie up' => [250000, 2500, 0, 3000, 189000, 2440, true, false];
        yield 'fractional minor unit rounds half up' => [101, 5000, 0, 1, 51, 4950, true, false];
        yield 'step 100 rounds down' => [149, 1000, 0, 100, 100, 3289, true, false];
        yield 'step 100 rounds a tie up' => [250, 4000, 0, 100, 200, 2000, true, false];
        yield 'step 1000 rounds a tie up' => [3000, 5000, 0, 1000, 2000, 3333, true, false];
        yield 'minor units round before the step' => [200, 2525, 0, 100, 200, 0, true, false];
        yield 'already an exact step' => [1000, 5000, 0, 100, 500, 5000, false, false];
        yield 'price floor raises the discounted price' => [100000, 5000, 6000, 1, 60000, 4000, false, true];
        yield 'exact floor overrides the step' => [1001, 5000, 6000, 100, 601, 3996, true, true];
        yield 'step rounding alone meets the floor' => [250, 4000, 8000, 100, 200, 2000, true, false];
        yield 'large step leaves price above floor' => [250000, 2500, 7000, 100000, 200000, 2000, true, false];
        yield 'nonmultiple floor overrides large step' => [250000, 2500, 7701, 10000, 192525, 2299, true, true];
        yield 'original price caps the rounded amount' => [960, 100, 0, 1000, 960, 0, true, false];
        yield 'original price caps the large-step amount' => [155000, 100, 0, 100000, 155000, 0, true, false];
        yield 'effective rate rounds half up' => [32, 300, 0, 1, 31, 313, true, false];
        yield 'zero-priced product' => [0, 5000, 0, 100000, 0, 0, false, false];
        yield 'one minor unit with a large step' => [1, 5000, 0, 100000, 0, 10000, true, false];
        yield 'full discount' => [1000, 10000, 0, 100, 0, 10000, false, false];
        yield 'matched rule with zero discount' => [1550, 0, 0, 10000, 1550, 0, false, false];
        $largestPrice = intdiv(PHP_INT_MAX, 10000);
        yield 'largest supported price' => [$largestPrice, 10000, 0, 1000, 0, 10000, false, false];
        yield 'largest price and rounding step' => [$largestPrice, 1, 0, PHP_INT_MAX, 0, 10000, true, false];
    }

    #[DataProvider('invalidPricingInputs')]
    public function testRejectsInvalidPricingInputs(array $overrides): void
    {
        $args = array_replace([
            'date' => '2026-01-01',
            'dateType' => 'manufacturing',
            'shelfLife' => 7,
            'unit' => 'days',
            'calendar' => 'gregorian',
            'asOfDate' => '2026-01-07',
            'originalPrice' => 100000,
            'rules' => self::RULES,
            'minimumPriceRatioBps' => 0,
            'roundingStep' => 1,
        ], $overrides);

        $this->expectException(InvalidArgumentException::class);

        // Reflection invokes the method with weak scalar coercion.
        (new ReflectionMethod(ExpiryPricingCalculator::class, 'price'))->invokeArgs(new ExpiryPricingCalculator(), $args);
    }

    public static function invalidPricingInputs(): iterable
    {
        yield 'negative original price' => [['originalPrice' => -1]];
        yield 'original price can overflow' => [['originalPrice' => intdiv(PHP_INT_MAX, 10000) + 1]];
        yield 'numeric string original price' => [['originalPrice' => '100000']];
        yield 'float original price' => [['originalPrice' => 100000.0]];
        yield 'numeric string shelf life' => [['shelfLife' => '7']];
        yield 'negative floor' => [['minimumPriceRatioBps' => -1]];
        yield 'floor above 100 percent' => [['minimumPriceRatioBps' => 10001]];
        yield 'numeric string floor' => [['minimumPriceRatioBps' => '6000']];
        yield 'zero rounding step' => [['roundingStep' => 0]];
        yield 'negative rounding step' => [['roundingStep' => -10]];
        yield 'float rounding step' => [['roundingStep' => 1.0]];
        yield 'numeric string rounding step' => [['roundingStep' => '10000']];
        yield 'null rounding step' => [['roundingStep' => null]];
        yield 'boolean rounding step' => [['roundingStep' => true]];
        yield 'array rounding step' => [['roundingStep' => [10000]]];
        yield 'rules collection is not an array' => [['rules' => 1]];
        yield 'rule is not an array' => [['rules' => ['rule']]];
        yield 'missing discount key' => [['rules' => [['remainingThresholdBps' => 3000]]]];
        yield 'unrecognized rule key' => [['rules' => [self::RULES[0] + ['extra' => 1]]]];
        yield 'negative discount' => [['rules' => [['remainingThresholdBps' => 3000, 'discountBps' => -1]]]];
        yield 'discount above 100 percent' => [['rules' => [['remainingThresholdBps' => 3000, 'discountBps' => 10001]]]];
        yield 'negative threshold' => [['rules' => [['remainingThresholdBps' => -1, 'discountBps' => 2000]]]];
        yield 'threshold above 100 percent' => [['rules' => [['remainingThresholdBps' => 10001, 'discountBps' => 2000]]]];
        yield 'numeric string discount' => [['rules' => [['remainingThresholdBps' => 3000, 'discountBps' => '2000']]]];
        yield 'float threshold' => [['rules' => [['remainingThresholdBps' => 3000.0, 'discountBps' => 2000]]]];
        yield 'malformed rules even at expiry' => [['asOfDate' => '2026-01-08', 'rules' => [[]]]];
        yield 'invalid reference date' => [['asOfDate' => '2026-02-30']];
    }
}
