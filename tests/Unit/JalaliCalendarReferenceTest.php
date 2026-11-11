<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Unit;

use DateTimeImmutable;
use Hekmatinasser\Verta\Verta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\ExpiryPricing\ExpiryPricingCalculator;
use Zarbinco\ExpiryPricing\Tests\Support\CalendarOracle;

require_once dirname(__DIR__) . '/Support/CalendarOracle.php';

final class JalaliCalendarReferenceTest extends TestCase
{
    #[DataProvider('referenceDates')]
    #[DataProvider('fixedCycleConversionDates')]
    public function testJalaliOrdinalsAndVertaAgreeWithReferenceDates(string $jalali, string $gregorian): void
    {
        $oracle = new CalendarOracle('jalali');
        $base = new DateTimeImmutable('1996-03-20T00:00:00+00:00');
        $reference = new DateTimeImmutable($gregorian . 'T00:00:00+00:00');
        $offset = (int) $base->diff($reference)->format('%r%a');

        self::assertSame($offset, $oracle->ordinal($jalali) - $oracle->ordinal('1375-01-01'));
        self::assertSame($jalali, $oracle->dateAt($oracle->ordinal('1375-01-01') + $offset));

        // Corroborate the resolved conversion dependency against the same fixed, independent pairs.
        $jalaliParts = array_map('intval', explode('-', $jalali));
        $gregorianParts = array_map('intval', explode('-', $gregorian));
        self::assertSame($gregorianParts, Verta::jalaliToGregorian(...$jalaliParts));
        self::assertSame($jalaliParts, Verta::GregorianToJalali(...$gregorianParts));
    }

    public static function referenceDates(): iterable
    {
        // Borkowski (1996), §1 and Table I, using Tehran mean time to determine Nowruz.
        // Month/day offsets use the published month lengths. The package's fixed cycle agrees here.
        // https://www.astro.uni.torun.pl/~kb/Papers/EMP/PersianC-EMP.htm
        yield '1300 Nowruz' => ['1300-01-01', '1921-03-21'];
        yield '1300 leap day' => ['1300-12-30', '1922-03-21'];
        yield '1301 Nowruz' => ['1301-01-01', '1922-03-22'];
        yield '1354 Nowruz' => ['1354-01-01', '1975-03-21'];
        yield '1354 leap day' => ['1354-12-30', '1976-03-20'];
        yield '1355 Nowruz' => ['1355-01-01', '1976-03-21'];
        yield '1375 Nowruz' => ['1375-01-01', '1996-03-20'];
        yield '1375 leap day' => ['1375-12-30', '1997-03-20'];
        yield '1376 Nowruz' => ['1376-01-01', '1997-03-21'];
        yield '1398 common year end' => ['1398-12-29', '2020-03-19'];
        yield '1399 Nowruz' => ['1399-01-01', '2020-03-20'];
        yield '1399 leap day' => ['1399-12-30', '2021-03-20'];
        yield '1400 Nowruz' => ['1400-01-01', '2021-03-21'];
        yield '1403 Nowruz' => ['1403-01-01', '2024-03-20'];
        yield '1403 leap day' => ['1403-12-30', '2025-03-20'];
        yield '1404 Nowruz' => ['1404-01-01', '2025-03-21'];
        yield '1405 Nowruz' => ['1405-01-01', '2026-03-21'];
        yield '1405 Shahrivar end' => ['1405-06-31', '2026-09-22'];
        yield '1405 Mehr end' => ['1405-07-30', '2026-10-22'];
        yield '1405 Aban start' => ['1405-08-01', '2026-10-23'];
    }

    public static function fixedCycleConversionDates(): iterable
    {
        // Extrapolate from 1375-01-01 = 1996-03-20 using the documented fixed cycle.
        // First date is 12 cycles earlier; last date is 8,624 years and 364 days later.
        yield 'minimum supported Jalali date' => ['0979-01-01', '1600-03-20'];
        yield 'maximum supported Jalali date' => ['9999-12-29', '+10621-03-19'];
        // Under the fixed cycle 1634 is leap, so 1635 starts after 366 days.
        yield 'fixed-cycle 1635 Nowruz' => ['1635-01-01', '2256-03-21'];
    }

    public function testDocumentedFixedCycleRetainsLeapDayAtAnAstronomicalCycleBreak(): void
    {
        // Fixed-cycle residue 17 makes 1634 leap. Borkowski's Table III instead starts
        // 1635 on 2256-03-20, 365 days after 1634-01-01, due to the break in Table II.
        $calculator = new ExpiryPricingCalculator();

        self::assertSame('1634-12-30', $calculator->expiryDate('1634-12-29', 1, 'days', 'jalali'));
        self::assertSame('1635-01-01', $calculator->expiryDate('1634-12-29', 2, 'days', 'jalali'));
        self::assertSame('1634-12-30', $calculator->shelfLifeStartDate('1635-01-01', 1, 'days', 'jalali'));
    }
}
