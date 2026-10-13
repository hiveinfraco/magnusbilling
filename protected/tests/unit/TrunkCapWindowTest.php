<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapWindow.php';

/**
 * Run: phpunit protected/tests/unit/TrunkCapWindowTest.php   (PHPUnit 9, no DB needed)
 */
class TrunkCapWindowTest extends TestCase
{
    private function policy(array $overrides = [])
    {
        return array_merge([
            'period_type'       => 'month',
            'period_length_sec' => null,
            'anchor'            => 'calendar',
            'reset_day'         => null,
            'timezone'          => 'Africa/Lagos',
            'window_start'      => null,
            'window_end'        => null,
            'weekdays'          => null,
        ], $overrides);
    }

    /** Epoch for a wall-clock time in a zone. */
    private function localTs($local, $tz = 'Africa/Lagos')
    {
        return (new DateTimeImmutable($local, new DateTimeZone($tz)))->getTimestamp();
    }

    private function assertPeriod($expectStart, $expectEnd, array $period, $tz = 'Africa/Lagos')
    {
        $fmt = function ($ts) use ($tz) {
            return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s');
        };
        $this->assertSame([$expectStart, $expectEnd], [$fmt($period['start']), $fmt($period['end'])]);
    }

    // ---- calendar anchors ---------------------------------------------------

    public function testCalendarMonthUsesLocalMidnightNotUtc()
    {
        // 2026-10-31 23:30 Lagos is already Oct 31 22:30 UTC; 2026-11-01 00:30 Lagos is Oct 31 23:30 UTC.
        $p = $this->policy();
        $this->assertPeriod('2026-10-01 00:00:00', '2026-11-01 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-31 23:30')));
        $this->assertPeriod('2026-11-01 00:00:00', '2026-12-01 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-11-01 00:30')));
    }

    public function testCalendarMonthBoundaryIsHalfOpen()
    {
        $p = $this->policy();
        $this->assertPeriod('2026-11-01 00:00:00', '2026-12-01 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-11-01 00:00:00')));
        $this->assertPeriod('2026-10-01 00:00:00', '2026-11-01 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-31 23:59:59')));
    }

    public function testCalendarMonthDecemberAndLeapFebruary()
    {
        $p = $this->policy();
        $this->assertPeriod('2026-12-01 00:00:00', '2027-01-01 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-12-31 12:00')));
        $this->assertPeriod('2028-02-01 00:00:00', '2028-03-01 00:00:00', TrunkCapWindow::period($p, $this->localTs('2028-02-29 12:00')));
    }

    public function testCalendarDayAndHour()
    {
        $ts = $this->localTs('2026-10-13 14:45:10');
        $this->assertPeriod('2026-10-13 00:00:00', '2026-10-14 00:00:00', TrunkCapWindow::period($this->policy(['period_type' => 'day']), $ts));
        $this->assertPeriod('2026-10-13 14:00:00', '2026-10-13 15:00:00', TrunkCapWindow::period($this->policy(['period_type' => 'hour']), $ts));
    }

    public function testCalendarWeekStartsMonday()
    {
        $p = $this->policy(['period_type' => 'week']);
        // 2026-10-13 is a Tuesday; 2026-10-18 is a Sunday; 2026-10-19 is a Monday.
        $this->assertPeriod('2026-10-12 00:00:00', '2026-10-19 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-13 09:00')));
        $this->assertPeriod('2026-10-12 00:00:00', '2026-10-19 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-18 23:59:59')));
        $this->assertPeriod('2026-10-19 00:00:00', '2026-10-26 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-19 00:00')));
    }

    public function testCalendarWeekAcrossYearEnd()
    {
        $p = $this->policy(['period_type' => 'week']);
        // 2026-12-31 is a Thursday.
        $this->assertPeriod('2026-12-28 00:00:00', '2027-01-04 00:00:00', TrunkCapWindow::period($p, $this->localTs('2027-01-01 10:00')));
    }

    public function testCustomCalendarAlignsToEpoch()
    {
        $p  = $this->policy(['period_type' => 'custom', 'period_length_sec' => 900]);
        $ts = gmmktime(10, 7, 30, 10, 13, 2026);
        $r  = TrunkCapWindow::period($p, $ts);
        $this->assertSame([gmmktime(10, 0, 0, 10, 13, 2026), gmmktime(10, 15, 0, 10, 13, 2026)], [$r['start'], $r['end']]);
        $this->assertFalse($r['rolling']);
    }

    // ---- billing-day anchors ------------------------------------------------

    public function testBillingDayMonthBeforeAndAfterReset()
    {
        $p = $this->policy(['anchor' => 'billing_day', 'reset_day' => 15]);
        $this->assertPeriod('2026-09-15 00:00:00', '2026-10-15 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-14 23:59:59')));
        $this->assertPeriod('2026-10-15 00:00:00', '2026-11-15 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-15 00:00:00')));
    }

    public function testBillingDayMonthAcrossYearEnd()
    {
        $p = $this->policy(['anchor' => 'billing_day', 'reset_day' => 20]);
        $this->assertPeriod('2026-12-20 00:00:00', '2027-01-20 00:00:00', TrunkCapWindow::period($p, $this->localTs('2027-01-05 08:00')));
    }

    public function testBillingDay31ClampsToShortMonths()
    {
        $p = $this->policy(['anchor' => 'billing_day', 'reset_day' => 31]);
        // Mid-February 2027 (not a leap year): period opened Jan 31, closes Feb 28.
        $this->assertPeriod('2027-01-31 00:00:00', '2027-02-28 00:00:00', TrunkCapWindow::period($p, $this->localTs('2027-02-10 12:00')));
        // Then Feb 28 -> Mar 31, and Apr 30 -> May 31.
        $this->assertPeriod('2027-02-28 00:00:00', '2027-03-31 00:00:00', TrunkCapWindow::period($p, $this->localTs('2027-03-15 12:00')));
        $this->assertPeriod('2027-04-30 00:00:00', '2027-05-31 00:00:00', TrunkCapWindow::period($p, $this->localTs('2027-05-01 12:00')));
    }

    public function testBillingDay30InLeapFebruary()
    {
        $p = $this->policy(['anchor' => 'billing_day', 'reset_day' => 30]);
        $this->assertPeriod('2028-01-30 00:00:00', '2028-02-29 00:00:00', TrunkCapWindow::period($p, $this->localTs('2028-02-29 00:00') - 1));
        $this->assertPeriod('2028-02-29 00:00:00', '2028-03-30 00:00:00', TrunkCapWindow::period($p, $this->localTs('2028-02-29 00:00')));
    }

    public function testBillingDayWeek()
    {
        // Reset on Wednesday (3). 2026-10-13 is a Tuesday, 2026-10-14 a Wednesday.
        $p = $this->policy(['period_type' => 'week', 'anchor' => 'billing_day', 'reset_day' => 3]);
        $this->assertPeriod('2026-10-07 00:00:00', '2026-10-14 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-13 23:00')));
        $this->assertPeriod('2026-10-14 00:00:00', '2026-10-21 00:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-14 00:00')));
    }

    // ---- rolling ------------------------------------------------------------

    public function testRollingHourCoversSixtyMinuteBucketsIncludingCurrent()
    {
        $p  = $this->policy(['period_type' => 'hour', 'anchor' => 'rolling']);
        $ts = gmmktime(10, 30, 45, 10, 13, 2026);
        $r  = TrunkCapWindow::period($p, $ts);
        $this->assertTrue($r['rolling']);
        $this->assertSame(gmmktime(10, 31, 0, 10, 13, 2026), $r['end']);
        $this->assertSame(gmmktime(9, 31, 0, 10, 13, 2026), $r['start']);
        $this->assertSame(60, ($r['end'] - $r['start']) / 60);
    }

    public function testRollingLengths()
    {
        $this->assertSame(86400, TrunkCapWindow::rollingLength($this->policy(['period_type' => 'day'])));
        $this->assertSame(604800, TrunkCapWindow::rollingLength($this->policy(['period_type' => 'week'])));
        $this->assertSame(TrunkCapWindow::ROLLING_MONTH_SECONDS, TrunkCapWindow::rollingLength($this->policy(['period_type' => 'month'])));
    }

    public function testRollingCustomRoundsUpToWholeMinutes()
    {
        $p = $this->policy(['period_type' => 'custom', 'anchor' => 'rolling', 'period_length_sec' => 90]);
        $r = TrunkCapWindow::period($p, gmmktime(10, 0, 5, 10, 13, 2026));
        $this->assertSame(120, $r['end'] - $r['start']);
    }

    // ---- timezones and DST --------------------------------------------------

    public function testOtherTimezoneMovesDayBoundary()
    {
        $ts = gmmktime(23, 30, 0, 10, 13, 2026); // Oct 13 23:30 UTC = Oct 14 00:30 Lagos
        $utc   = TrunkCapWindow::period($this->policy(['period_type' => 'day', 'timezone' => 'UTC']), $ts);
        $lagos = TrunkCapWindow::period($this->policy(['period_type' => 'day']), $ts);
        $this->assertSame(gmmktime(0, 0, 0, 10, 13, 2026), $utc['start']);
        $this->assertSame(gmmktime(23, 0, 0, 10, 13, 2026), $lagos['start']);
    }

    public function testHalfHourOffsetHours()
    {
        $p = $this->policy(['period_type' => 'hour', 'timezone' => 'Asia/Kolkata']);
        $this->assertPeriod('2026-10-13 14:00:00', '2026-10-13 15:00:00', TrunkCapWindow::period($p, $this->localTs('2026-10-13 14:59', 'Asia/Kolkata')), 'Asia/Kolkata');
    }

    public function testDstShortDayIs23Hours()
    {
        // Europe/London springs forward 2026-03-29 01:00 -> 02:00.
        $r = TrunkCapWindow::period($this->policy(['period_type' => 'day', 'timezone' => 'Europe/London']), $this->localTs('2026-03-29 12:00', 'Europe/London'));
        $this->assertSame(23 * 3600, $r['end'] - $r['start']);
    }

    public function testDstRepeatedHourGivesTwoDistinctPeriods()
    {
        // Europe/London falls back 2026-10-25 02:00 BST -> 01:00 GMT, so 01:xx happens twice.
        $p      = $this->policy(['period_type' => 'hour', 'timezone' => 'Europe/London']);
        $first  = TrunkCapWindow::period($p, gmmktime(0, 30, 0, 10, 25, 2026));  // 01:30 BST
        $second = TrunkCapWindow::period($p, gmmktime(1, 30, 0, 10, 25, 2026));  // 01:30 GMT
        $this->assertSame(3600, $first['end'] - $first['start']);
        $this->assertSame($first['end'], $second['start']);
    }

    public function testPeriodsTileWithoutGapsOrOverlap()
    {
        $policies = [
            $this->policy(['period_type' => 'day', 'timezone' => 'Europe/London']),
            $this->policy(['period_type' => 'week']),
            $this->policy(['anchor' => 'billing_day', 'reset_day' => 31]),
            $this->policy(['period_type' => 'week', 'anchor' => 'billing_day', 'reset_day' => 5]),
        ];
        foreach ($policies as $p) {
            $period = TrunkCapWindow::period($p, gmmktime(0, 0, 0, 1, 1, 2026));
            for ($i = 0; $i < 60; $i++) {
                $next = TrunkCapWindow::period($p, $period['end']);
                $this->assertSame($period['end'], $next['start'], json_encode($p));
                $this->assertSame($next, TrunkCapWindow::period($p, $next['end'] - 1));
                $period = $next;
            }
        }
    }

    // ---- time-of-day / weekday windows -------------------------------------

    public function testNoWindowAlwaysApplies()
    {
        $this->assertTrue(TrunkCapWindow::appliesAt($this->policy(), $this->localTs('2026-10-13 03:00')));
    }

    public function testWeekdaysOnly()
    {
        $p = $this->policy(['weekdays' => 'sat,sun']);
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-16 23:59')));  // Friday
        $this->assertTrue(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-17 00:00')));   // Saturday
    }

    public function testDaytimeWindowIsHalfOpen()
    {
        $p = $this->policy(['window_start' => '08:00:00', 'window_end' => '18:00:00', 'weekdays' => 'mon,tue,wed,thu,fri']);
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-13 07:59:59')));
        $this->assertTrue(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-13 08:00:00')));
        $this->assertTrue(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-13 17:59:59')));
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-13 18:00:00')));
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-17 12:00')));   // Saturday
    }

    public function testOvernightWindowBelongsToOpeningDay()
    {
        $p = $this->policy(['window_start' => '22:00', 'window_end' => '06:00', 'weekdays' => 'fri']);
        $this->assertTrue(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-16 23:00')));   // Fri night
        $this->assertTrue(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-17 03:00')));   // Sat early, opened Fri
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-16 03:00')));  // Fri early, opened Thu
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-17 12:00')));  // Sat midday
        $this->assertFalse(TrunkCapWindow::appliesAt($p, $this->localTs('2026-10-17 23:00')));  // Sat night
    }

    public function testWindowUsesPolicyTimezone()
    {
        $p  = $this->policy(['window_start' => '08:00', 'window_end' => '18:00']);
        $ts = gmmktime(7, 30, 0, 10, 13, 2026); // 07:30 UTC = 08:30 Lagos
        $this->assertTrue(TrunkCapWindow::appliesAt($p, $ts));
        $this->assertFalse(TrunkCapWindow::appliesAt(array_merge($p, ['timezone' => 'UTC']), $ts));
    }

    // ---- helpers ------------------------------------------------------------

    public function testDbRoundTripIsUtc()
    {
        $ts = gmmktime(23, 5, 0, 10, 13, 2026);
        $this->assertSame('2026-10-13 23:05:00', TrunkCapWindow::toDb($ts));
        $this->assertSame($ts, TrunkCapWindow::fromDb('2026-10-13 23:05:00'));
        $this->assertSame(gmmktime(23, 5, 0, 10, 13, 2026), TrunkCapWindow::minuteStart($ts + 59));
    }

    public function testInvalidCustomLengthThrows()
    {
        $this->expectException(InvalidArgumentException::class);
        TrunkCapWindow::period($this->policy(['period_type' => 'custom', 'period_length_sec' => 0]), time());
    }
}
