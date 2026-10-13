<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapReconciler.php';

/**
 * TrunkCapReconciler against a migrated throwaway database (see TrunkCapServiceTest).
 * Creates only tctest-* trunks, prefixes and CDRs and removes them afterwards.
 */
class TrunkCapReconcilerTest extends TestCase
{
    /** @var PDO */
    private static $db;
    private $trunk;
    private $prefix;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TRUNKCAP_TEST_DSN')) {
            self::$db = new PDO(getenv('TRUNKCAP_TEST_DSN'), getenv('TRUNKCAP_TEST_USER') ?: 'root',
                getenv('TRUNKCAP_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
    }

    protected function setUp(): void
    {
        if (! self::$db) {
            $this->markTestSkipped('TRUNKCAP_TEST_DSN not set');
        }
        $db = self::$db;
        $db->exec("INSERT INTO pkg_provider (provider_name) SELECT 'tctest-recon' WHERE NOT EXISTS (SELECT 1 FROM pkg_provider WHERE provider_name = 'tctest-recon')");
        $provider = (int) $db->query("SELECT id FROM pkg_provider WHERE provider_name = 'tctest-recon'")->fetchColumn();
        $db->prepare("INSERT INTO pkg_trunk (id_provider, trunkcode, host, fromdomain, providertech, providerip, user, secret, allow, link_sms, sms_res, language)
            VALUES (?, ?, 'x', 'x', 'pjsip', 'x', '', '', 'ulaw', '', '', 'en')")->execute([$provider, 'tctest-r-' . bin2hex(random_bytes(3))]);
        $this->trunk = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO pkg_prefix (prefix, destination) VALUES (?, 'tctest')")->execute(['999' . mt_rand(100000, 999999)]);
        $this->prefix = (int) $db->lastInsertId();
        // Provider bills this prefix 60/60.
        $db->prepare('INSERT INTO pkg_rate_provider (id_provider, id_prefix, buyrate, buyrateinitblock, buyrateincrement) VALUES (?, ?, 0.01, 60, 60)')
            ->execute([$provider, $this->prefix]);
    }

    protected function tearDown(): void
    {
        if (! self::$db) {
            return;
        }
        self::$db->exec("DELETE FROM pkg_cdr WHERE id_trunk = {$this->trunk}");
        self::$db->exec("DELETE FROM pkg_cdr_failed WHERE id_trunk = {$this->trunk}");
        self::$db->exec("DELETE FROM pkg_rate_provider WHERE id_prefix = {$this->prefix}");
        self::$db->exec("DELETE FROM pkg_prefix WHERE id = {$this->prefix}");
        self::$db->exec("DELETE FROM pkg_trunk WHERE id = {$this->trunk}");
    }

    /** CDR with starttime in UTC (the reconciler is told CDRs are UTC). */
    private function cdr($startUtc, $seconds, $prefix = null)
    {
        self::$db->prepare("INSERT INTO pkg_cdr (id_user, id_plan, id_trunk, uniqueid, starttime, sessiontime, real_sessiontime,
                calledstation, src, sipiax, sessionbill, id_prefix, terminatecauseid) VALUES (1, 1, ?, ?, ?, ?, ?, '1', '1', 0, 0, ?, 1)")
            ->execute([$this->trunk, uniqid('tc', true), $startUtc, $seconds, $seconds, $prefix === null ? $this->prefix : $prefix]);
    }

    private function reconciler()
    {
        return new TrunkCapReconciler(self::$db, 'UTC');
    }

    public function testRoundsWithProviderIncrementsAndHonoursStatementTimezone()
    {
        $this->cdr('2026-08-31 22:30:00', 600);  // 2026-08-31 23:30 Lagos: before September
        $this->cdr('2026-08-31 23:30:00', 61);   // 2026-09-01 00:30 Lagos: in, bills 120
        $this->cdr('2026-09-15 12:00:00', 30);   // in, bills 60 (initial block)
        $this->cdr('2026-09-30 22:59:59', 120);  // 23:59:59 Lagos on the 30th: in, bills 120
        $this->cdr('2026-09-30 23:00:00', 600);  // 00:00 Lagos on Oct 1: out
        self::$db->prepare("INSERT INTO pkg_cdr_failed (id_user, id_plan, id_trunk, uniqueid, starttime, calledstation, src, sipiax, id_prefix, terminatecauseid)
            VALUES (1, 1, ?, 'tcf', '2026-09-10 10:00:00', '1', '1', 0, 1, 4)")->execute([$this->trunk]);

        $u = $this->reconciler()->ourUsage($this->trunk, '2026-09-01', '2026-09-30', 'Africa/Lagos');
        $this->assertSame(['calls' => 3, 'real_seconds' => 211, 'billed_seconds' => 300, 'failed_attempts' => 1, 'counter_seconds' => null], $u);
    }

    public function testPolicyRoundingOverrideWins()
    {
        $this->cdr('2026-09-15 12:00:00', 61);
        self::$db->prepare("INSERT INTO pkg_trunk_cap_policy (id_trunk, name, max_minutes, rounding_init_sec, rounding_inc_sec) VALUES (?, 'o', 1000, 1, 1)")
            ->execute([$this->trunk]);
        $this->assertSame(61, $this->reconciler()->ourUsage($this->trunk, '2026-09-01', '2026-09-30', 'Africa/Lagos')['billed_seconds']);
    }

    public function testCompareFlagsDifferencesBeyondTolerance()
    {
        $this->cdr('2026-09-15 12:00:00', 6000); // 100 min
        $base = ['id_trunk' => $this->trunk, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'timezone' => 'Africa/Lagos', 'provider_calls' => '1'];

        $ok = $this->reconciler()->compare($base + ['provider_minutes' => '101.50'], 2);
        $expected = ['our_minutes' => 100.0, 'provider_minutes' => 101.5, 'diff_pct' => -1.48, 'status' => 'ok', 'provider_calls' => 1];
        $actual   = array_intersect_key($ok, $expected);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);

        $bad = $this->reconciler()->compare($base + ['provider_minutes' => '90'], 2);
        $this->assertSame([11.11, 'mismatch'], [$bad['diff_pct'], $bad['status']]);
    }

    public function testCalendarMonthCounterIsIncludedOnlyForExactMonths()
    {
        self::$db->prepare("INSERT INTO pkg_trunk_cap_policy (id_trunk, name, max_minutes, period_type, anchor, timezone) VALUES (?, 'm', 1000, 'month', 'calendar', 'Africa/Lagos')")
            ->execute([$this->trunk]);
        $policy = (int) self::$db->lastInsertId();
        self::$db->prepare("INSERT INTO pkg_trunk_cap_usage (id_policy, window_start, billed_seconds) VALUES (?, '2026-08-31 23:00:00', 4200)")->execute([$policy]);

        $r = $this->reconciler();
        $this->assertSame(4200, $r->ourUsage($this->trunk, '2026-09-01', '2026-09-30', 'Africa/Lagos')['counter_seconds']);
        $this->assertNull($r->ourUsage($this->trunk, '2026-09-01', '2026-09-15', 'Africa/Lagos')['counter_seconds']);
        $this->assertSame(0, $r->ourUsage($this->trunk, '2026-10-01', '2026-10-31', 'Africa/Lagos')['counter_seconds']);
    }

    public function testMonthlyListsStatementsAndCappedTrunksWithoutOne()
    {
        $this->cdr('2026-09-15 12:00:00', 600);
        self::$db->prepare("INSERT INTO pkg_trunk_cap_policy (id_trunk, name, max_minutes) VALUES (?, 'm', 1000)")->execute([$this->trunk]);

        $mine = function ($rows) {
            return array_values(array_filter($rows, function ($r) {
                return (int) $r['id_trunk'] === $this->trunk;
            }));
        };
        $rows = $mine($this->reconciler()->monthly('2026-09', 2));
        $this->assertCount(1, $rows);
        $this->assertSame(['no_statement', 10.0], [$rows[0]['status'], $rows[0]['our_minutes']]);

        self::$db->prepare("INSERT INTO pkg_trunk_cap_statement (id_trunk, period_start, period_end, provider_minutes) VALUES (?, '2026-09-01', '2026-09-30', 10)")
            ->execute([$this->trunk]);
        $rows = $mine($this->reconciler()->monthly('2026-09', 2));
        $this->assertCount(1, $rows);
        $this->assertSame(['ok', 0.0], [$rows[0]['status'], $rows[0]['diff_pct']]);
    }
}
