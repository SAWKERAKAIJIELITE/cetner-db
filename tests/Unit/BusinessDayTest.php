<?php

namespace Tests\Unit;

use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessDayTest extends TestCase
{
    public function test_the_next_day_starts_at_midnight_in_damascus(): void
    {
        $this->assertSame('2026-11-21T21:00:00+00:00', BusinessDay::startOfNext(Carbon::parse('2026-11-21 08:00:00'))->toIso8601String());
        // 22:30 UTC is already the next day in Damascus.
        $this->assertSame('2026-11-22T21:00:00+00:00', BusinessDay::startOfNext(Carbon::parse('2026-11-21 22:30:00'))->toIso8601String());
    }

    public function test_a_business_day_is_bounded_in_utc(): void
    {
        [$start, $end] = BusinessDay::bounds('2026-11-21');

        $this->assertSame('2026-11-20T21:00:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-11-21T21:00:00+00:00', $end->toIso8601String());
    }
}
