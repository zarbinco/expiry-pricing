<?php

declare(strict_types=1);

namespace Zarbinco\ExpiryPricing\Tests\Stress;

use Zarbinco\ExpiryPricing\Tests\Support\CalendarPropertyCase;

require_once dirname(__DIR__) . '/Support/CalendarPropertyCase.php';

final class CalendarPropertyStressTest extends CalendarPropertyCase
{
    protected const int SAMPLE_SIZE = 2000;
    protected const int SEED_OFFSET = 31000;
}
