<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    private function at(string $utc): Clock
    {
        $time = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return new Clock(fn () => $time);
    }

    public function testLocalDateFollowsTheUsersZone(): void
    {
        // 03:30 UTC on Nov 12 is still the evening of Nov 11 in Chicago (UTC-6).
        $clock = $this->at('2026-11-12 03:30:00');

        $this->assertSame('2026-11-12', $clock->today('UTC'));
        $this->assertSame('2026-11-11', $clock->today('America/Chicago'));
        $this->assertSame('2026-11-12', $clock->today('Pacific/Auckland'));
    }

    public function testDayRollsOverAtLocalMidnight(): void
    {
        $this->assertSame('2026-11-11', $this->at('2026-11-12 05:59:59')->today('America/Chicago'));
        $this->assertSame('2026-11-12', $this->at('2026-11-12 06:00:00')->today('America/Chicago'));
    }

    public function testNextMidnightIsInTheUsersZone(): void
    {
        $next = $this->at('2026-11-12 03:30:00')->nextMidnight('America/Chicago');

        $this->assertSame('2026-11-12T00:00:00-06:00', $next->format(DATE_ATOM));
    }

    public function testDaylightSavingDayStillEndsAtMidnight(): void
    {
        // US clocks fall back on Nov 1, 2026: that local day is 25 hours long.
        $next = $this->at('2026-11-01 12:00:00')->nextMidnight('America/Chicago');

        $this->assertSame('2026-11-02T00:00:00-06:00', $next->format(DATE_ATOM));
    }
}
