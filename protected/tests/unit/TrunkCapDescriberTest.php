<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapDescriber.php';

class TrunkCapDescriberTest extends TestCase
{
    private function describe(array $p)
    {
        return TrunkCapDescriber::describe(array_merge([
            'period_type' => 'month', 'anchor' => 'calendar', 'timezone' => 'Africa/Lagos',
            'safety_margin_pct' => 100, 'on_limit' => 'skip_trunk', 'reserve_mode' => 'adaptive',
        ], $p));
    }

    public function testSpecExample()
    {
        $this->assertSame(
            'Max 50,000 minutes per calendar month (resets on the 1st, Africa/Lagos time). When reached, calls fail over to the next trunk.',
            $this->describe(['max_minutes' => 50000])
        );
    }

    public function testBillingDayMarginRejectAndFullReserve()
    {
        $this->assertSame(
            'Max 30 minutes per billing month (resets on the 31st or the last day of shorter months, Africa/Lagos time).'
                . ' Period limits enforced at 95%. When reached, the call is rejected. Each call reserves its full maximum duration.',
            $this->describe(['max_minutes' => 30, 'anchor' => 'billing_day', 'reset_day' => 31, 'safety_margin_pct' => 95,
                'on_limit' => 'reject', 'reserve_mode' => 'full'])
        );
    }

    public function testWindowAndExtraLimits()
    {
        $this->assertSame(
            'Max 10 calls per day (resets at midnight, UTC time). Also max 5 calls per rolling hour and 1 concurrent call.'
                . ' Applies only to calls starting between 08:00 and 18:00 on Mon, Tue and Wed, UTC time.'
                . ' When reached, calls fail over to the next trunk.',
            $this->describe(['period_type' => 'day', 'timezone' => 'UTC', 'max_calls' => 10, 'max_calls_per_hour' => 5,
                'max_concurrent' => 1, 'window_start' => '08:00:00', 'window_end' => '18:00:00', 'weekdays' => 'mon,tue,wed'])
        );
    }

    public function testRollingAttemptsAndCustom()
    {
        $this->assertSame(
            'Max 20 call attempts in any rolling hour. When reached, calls fail over to the next trunk.',
            $this->describe(['period_type' => 'hour', 'anchor' => 'rolling', 'max_calls' => 20, 'count_failed_attempts' => 1])
        );
        $this->assertStringStartsWith(
            'Max 100 calls per fixed 15 minutes block (aligned to UTC).',
            $this->describe(['period_type' => 'custom', 'period_length_sec' => 900, 'max_calls' => 100])
        );
        $this->assertStringStartsWith(
            'Max 1 minute in any rolling 2 hours.',
            $this->describe(['period_type' => 'custom', 'anchor' => 'rolling', 'period_length_sec' => 7200, 'max_minutes' => 1])
        );
    }

    public function testOnlyTrunkWideLimitsAndOrdinals()
    {
        $this->assertStringStartsWith('Max 3 calls per second.', $this->describe(['max_cps' => 3]));
        $this->assertStringContainsString('resets on the 2nd', $this->describe(['max_calls' => 1, 'anchor' => 'billing_day', 'reset_day' => 2]));
        $this->assertStringContainsString('resets on the 13th', $this->describe(['max_calls' => 1, 'anchor' => 'billing_day', 'reset_day' => 13]));
        $this->assertStringContainsString('resets on the 23rd', $this->describe(['max_calls' => 1, 'anchor' => 'billing_day', 'reset_day' => 23]));
        $this->assertStringContainsString('resets every Wednesday', $this->describe(['max_calls' => 1, 'period_type' => 'week', 'anchor' => 'billing_day', 'reset_day' => 3]));
        $this->assertSame('No limits set.', $this->describe([]));
    }

    public function testPausedAndStrict()
    {
        $text = $this->describe(['max_calls' => 5, 'strict' => 1, 'override_until' => '2026-10-14 08:00:00']);
        $this->assertStringContainsString('Strict: the trunk is blocked if the cap check fails.', $text);
        $this->assertStringEndsWith('Paused until 2026-10-14 08:00:00 UTC.', $text);
    }
}
