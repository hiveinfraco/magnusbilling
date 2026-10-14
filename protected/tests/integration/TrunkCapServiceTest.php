<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapService.php';

/**
 * TrunkCapService against a real MariaDB with the MagnusBilling schema and
 * the trunk caps migrations applied. Skipped unless TRUNKCAP_TEST_DSN is set.
 *
 *   TRUNKCAP_TEST_DSN='mysql:host=127.0.0.1;dbname=mbilling' TRUNKCAP_TEST_USER=... TRUNKCAP_TEST_PASS=... \
 *     phpunit -c protected/tests/phpunit.xml.dist --testsuite integration
 *
 * Creates and deletes only rows for trunks named tctest-*. However, the sweeper test
 * runs TrunkCapService::sweep() with a clock a day ahead, and sweep() is global:
 * it also releases any other open reservations in the database. Use a throwaway
 * database, never production or a dev system carrying traffic.
 */
class TrunkCapServiceTest extends TestCase
{
    /** @var PDO */
    private static $db;
    private $now;
    private $trunks = [];
    private $log    = [];

    public static function setUpBeforeClass(): void
    {
        if (! getenv('TRUNKCAP_TEST_DSN')) {
            return;
        }
        self::$db = new PDO(getenv('TRUNKCAP_TEST_DSN'), getenv('TRUNKCAP_TEST_USER') ?: 'root',
            getenv('TRUNKCAP_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$db->exec("INSERT INTO pkg_provider (provider_name) SELECT 'tctest-provider'
            WHERE NOT EXISTS (SELECT 1 FROM pkg_provider WHERE provider_name = 'tctest-provider')");
    }

    protected function setUp(): void
    {
        if (! self::$db) {
            $this->markTestSkipped('TRUNKCAP_TEST_DSN not set');
        }
        $this->now = (float) gmmktime(10, 0, 0, 10, 13, 2026); // Tuesday 11:00 Lagos
    }

    protected function tearDown(): void
    {
        foreach ($this->trunks as $id) {
            self::$db->exec('DELETE FROM pkg_trunk_cap_reservation WHERE id_trunk = ' . (int) $id);
            self::$db->exec('DELETE FROM pkg_trunk_cap_event WHERE id_trunk = ' . (int) $id);
            self::$db->exec('DELETE FROM pkg_cdr WHERE id_trunk = ' . (int) $id);
            self::$db->exec('DELETE FROM pkg_cdr_failed WHERE id_trunk = ' . (int) $id);
            self::$db->exec('DELETE FROM pkg_trunk WHERE id = ' . (int) $id); // cascades policies, usage, minutes, lock
        }
    }

    // ---- fixtures -----------------------------------------------------------

    private function trunk()
    {
        $code = 'tctest-' . bin2hex(random_bytes(4));
        $stmt = self::$db->prepare("INSERT INTO pkg_trunk (id_provider, trunkcode, host, fromdomain, providertech, providerip,
                user, secret, allow, link_sms, sms_res, language)
            SELECT id, ?, '192.0.2.1', '192.0.2.1', 'pjsip', '192.0.2.1', '', '', 'ulaw', '', '', 'en'
            FROM pkg_provider WHERE provider_name = 'tctest-provider'");
        $stmt->execute([$code]);
        return $this->trunks[] = (int) self::$db->lastInsertId();
    }

    private function policy($idTrunk, array $overrides)
    {
        $row = array_merge([
            'id_trunk' => $idTrunk, 'name' => 'test', 'active' => 1, 'period_type' => 'month', 'anchor' => 'calendar',
            'timezone' => 'Africa/Lagos', 'safety_margin_pct' => 100, 'warn_pct' => '', 'on_limit' => 'skip_trunk',
            'reserve_mode' => 'adaptive', 'count_failed_attempts' => 0, 'strict' => 0,
        ], $overrides);
        $stmt = self::$db->prepare('INSERT INTO pkg_trunk_cap_policy (' . implode(',', array_keys($row)) . ')
            VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        $stmt->execute(array_values($row));
        return (int) self::$db->lastInsertId();
    }

    /** CDR on a trunk; $ts is UTC epoch (starttime is a TIMESTAMP, so stored through FROM_UNIXTIME). */
    private function cdr($idTrunk, $uniqueid, $ts, $seconds, $idPrefix = 1)
    {
        self::$db->prepare("INSERT INTO pkg_cdr (id_user, id_plan, id_trunk, uniqueid, starttime, sessiontime, real_sessiontime,
                calledstation, src, sipiax, sessionbill, id_prefix, terminatecauseid)
            VALUES (1, 1, ?, ?, FROM_UNIXTIME(?), ?, ?, '000', '000', 0, 0, ?, 1)")
            ->execute([$idTrunk, $uniqueid, $ts, $seconds, $seconds, $idPrefix]);
    }

    private function failedCdr($idTrunk, $uniqueid, $ts)
    {
        self::$db->prepare("INSERT INTO pkg_cdr_failed (id_user, id_trunk, uniqueid, starttime, calledstation, src)
            VALUES (1, ?, ?, FROM_UNIXTIME(?), '000', '000')")->execute([$idTrunk, $uniqueid, $ts]);
    }

    private function createdAt($idPolicy, $ts)
    {
        self::$db->exec('UPDATE pkg_trunk_cap_policy SET created_at = FROM_UNIXTIME(' . (int) $ts . ') WHERE id = ' . (int) $idPolicy);
    }

    private function prefixId($prefix)
    {
        self::$db->exec("INSERT INTO pkg_prefix (prefix, destination) SELECT '$prefix', 'tctest'
            WHERE NOT EXISTS (SELECT 1 FROM pkg_prefix WHERE prefix = '$prefix')");
        return (int) self::$db->query("SELECT id FROM pkg_prefix WHERE prefix = '$prefix'")->fetchColumn();
    }

    private function service($db = null)
    {
        return new TrunkCapService($db ?: self::$db, function ($message, $error) {
            $this->log[] = ($error ? 'ERROR ' : '') . $message;
        }, function () {
            return $this->now;
        });
    }

    /** Usage row as strings, whatever the PDO driver's native typing. */
    private function usage($idPolicy)
    {
        $row = self::$db->query('SELECT calls, attempts, reserved_calls, billed_seconds, reserved_seconds, warned_pct
            FROM pkg_trunk_cap_usage WHERE id_policy = ' . (int) $idPolicy)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? false : array_map('strval', $row);
    }

    private function countRows($sql)
    {
        return (string) self::$db->query($sql)->fetchColumn();
    }

    private function events($idTrunk, $event)
    {
        return self::$db->query("SELECT * FROM pkg_trunk_cap_event WHERE id_trunk = $idTrunk AND event = '$event'")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---- basics -------------------------------------------------------------

    public function testTrunkWithoutPoliciesIsAllowedWithoutReservation()
    {
        $d = $this->service()->reserve($this->trunk(), 'u1', 600);
        $this->assertTrue($d['allow']);
        $this->assertNull($d['reservation']);
        $this->assertSame(600, $d['timeout']);
    }

    public function testMaxCallsCountsInFlightAndAnsweredCalls()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 3]);
        $s = $this->service();

        $r = [];
        for ($i = 0; $i < 3; $i++) {
            $d = $s->reserve($t, "u$i", 600);
            $this->assertTrue($d['allow'], "call $i");
            $r[] = $d['reservation'];
        }
        $blocked = $s->reserve($t, 'u3', 600);
        $this->assertFalse($blocked['allow']);
        $this->assertSame('skip_trunk', $blocked['action']);
        $this->assertSame('max_calls', $blocked['limit']);
        $blockedEvents = $this->events($t, 'blocked');
        $this->assertCount(1, $blockedEvents);
        $this->assertSame(['limit_type' => 'max_calls', 'used' => 3, 'limit' => 3, 'effective' => 3],
            array_intersect_key(json_decode($blockedEvents[0]['snapshot'], true), array_flip(['limit_type', 'used', 'limit', 'effective'])));

        // An unanswered call frees its slot; an answered one keeps it.
        $this->assertTrue($s->commit($r[0], 'NOANSWER', 0));
        $this->assertTrue($s->commit($r[1], 'ANSWER', 30));
        $this->assertSame(['calls' => '1', 'attempts' => '3', 'reserved_calls' => '1'],
            array_intersect_key($this->usage($p), array_flip(['calls', 'attempts', 'reserved_calls'])));
        $this->assertTrue($s->reserve($t, 'u4', 600)['allow']);
        $this->assertFalse($s->reserve($t, 'u5', 600)['allow']);
    }

    public function testCountFailedAttemptsCountsUnansweredCalls()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_calls' => 2, 'count_failed_attempts' => 1]);
        $s = $this->service();
        $s->commit($s->reserve($t, 'a', 600)['reservation'], 'BUSY', 0);
        $s->commit($s->reserve($t, 'b', 600)['reservation'], 'CHANUNAVAIL', 0);
        $this->assertFalse($s->reserve($t, 'c', 600)['allow']);
    }

    public function testSafetyMarginLowersTheLimit()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_calls' => 10, 'safety_margin_pct' => 50]);
        $s = $this->service();
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($s->reserve($t, "u$i", 600)['allow']);
        }
        $this->assertFalse($s->reserve($t, 'u5', 600)['allow']);
    }

    public function testSafetyMarginDoesNotReduceConcurrencyOrCps()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_concurrent' => 2, 'max_cps' => 2, 'safety_margin_pct' => 95]);
        $s = $this->service();
        $this->assertTrue($s->reserve($t, 'a', 600)['allow']);
        $this->assertTrue($s->reserve($t, 'b', 600)['allow']); // floor(2 x 95%) would have been 1
        $this->assertFalse($s->reserve($t, 'c', 600)['allow']);
    }

    public function testCommitIsIdempotent()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 5]);
        $s = $this->service();
        $id = $s->reserve($t, 'u', 600)['reservation'];
        $this->assertTrue($s->commit($id, 'ANSWER', 10));
        $this->assertFalse($s->commit($id, 'ANSWER', 10));
        $this->assertSame('1', $this->usage($p)['calls']);
    }

    // ---- minutes and reservation sizing -------------------------------------

    public function testAdaptiveReservationFarFromCapReservesExpectedLength()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_minutes' => 100]); // 6000 s >= 2 x 600
        $d = $this->service()->reserve($t, 'u', 600);
        $this->assertSame(600, $d['timeout']);
        $this->assertSame('180', $this->usage($p)['reserved_seconds']);
    }

    public function testExpectedLengthLearnsFromAnsweredCalls()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_minutes' => 10000]);
        $s = $this->service();
        for ($i = 0; $i < 10; $i++) {
            $s->commit($s->reserve($t, "u$i", 600)['reservation'], 'ANSWER', 290);
        }
        $s->reserve($t, 'next', 600);
        $this->assertSame('300', $this->usage($p)['reserved_seconds']); // 290 s average rounded up to whole minutes
    }

    public function testNearCapReservesFullLengthThenShortensThenBlocks()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_minutes' => 15]); // 900 s < 2 x 600
        $s = $this->service();

        $first = $s->reserve($t, 'a', 600);
        $this->assertSame(600, $first['timeout']);
        $this->assertSame('600', $this->usage($p)['reserved_seconds']);

        $second = $s->reserve($t, 'b', 600);
        $this->assertTrue($second['allow']);
        $this->assertSame(300, $second['timeout']);
        $this->assertTrue($second['limited']);

        $third = $s->reserve($t, 'c', 600);
        $this->assertFalse($third['allow']);
        $this->assertSame('max_minutes', $third['limit']);

        // Both calls run to their limit: exactly the cap, never more.
        $s->commit($first['reservation'], 'ANSWER', 600);
        $s->commit($second['reservation'], 'ANSWER', 300);
        $u = $this->usage($p);
        $this->assertSame(['900', '0'], [$u['billed_seconds'], $u['reserved_seconds']]);
    }

    public function testFullReserveModeAlwaysReservesMaximumDuration()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_minutes' => 100, 'reserve_mode' => 'full']);
        $this->service()->reserve($t, 'u', 600);
        $this->assertSame('600', $this->usage($p)['reserved_seconds']);
    }

    public function testTightestPolicyLimitsTheCallAndAllReservationsMatchIt()
    {
        $t  = $this->trunk();
        $p1 = $this->policy($t, ['max_minutes' => 10000, 'reserve_mode' => 'full']);
        $p2 = $this->policy($t, ['period_type' => 'day', 'max_minutes' => 5]); // 300 s left today
        $d  = $this->service()->reserve($t, 'u', 600);
        $this->assertSame(300, $d['timeout']);
        $this->assertSame('300', $this->usage($p1)['reserved_seconds']);
        $this->assertSame('300', $this->usage($p2)['reserved_seconds']);
    }

    public function testProviderRoundingAndPolicyOverride()
    {
        $t  = $this->trunk();
        $p1 = $this->policy($t, ['max_minutes' => 1000]);
        $p2 = $this->policy($t, ['max_minutes' => 1000, 'rounding_init_sec' => 1, 'rounding_inc_sec' => 1]);
        $s  = $this->service();
        $s->commit($s->reserve($t, 'u', 600)['reservation'], 'ANSWER', 61, 60, 60);
        $this->assertSame('120', $this->usage($p1)['billed_seconds']);
        $this->assertSame('61', $this->usage($p2)['billed_seconds']);
    }

    // ---- concurrency and CPS ------------------------------------------------

    public function testMaxConcurrentAndRelease()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_concurrent' => 2]);
        $s = $this->service();
        $a = $s->reserve($t, 'a', 600);
        $this->now += 5;
        $s->reserve($t, 'b', 600);
        $this->now += 5;
        $this->assertSame('max_concurrent', $s->reserve($t, 'c', 600)['limit']);
        $s->commit($a['reservation'], 'ANSWER', 10);
        $this->assertTrue($s->reserve($t, 'd', 600)['allow']);
    }

    public function testMaxCpsUsesTheLastSecondIncludingFinishedCalls()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_cps' => 2]);
        $s = $this->service();
        $s->commit($s->reserve($t, 'a', 600)['reservation'], 'CHANUNAVAIL', 0);
        $this->now += 0.3;
        $s->reserve($t, 'b', 600);
        $this->now += 0.3;
        $this->assertSame('max_cps', $s->reserve($t, 'c', 600)['limit']);
        $this->now += 0.5; // first call is now > 1 s old
        $this->assertTrue($s->reserve($t, 'd', 600)['allow']);
    }

    public function testHundredSimultaneousCallsOnTrunkLimitedToTenAdmitExactlyTen()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_concurrent' => 10]);
        $start  = microtime(true) + 1.5;
        $worker = __DIR__ . '/TrunkCapReserveWorker.php';
        $procs  = [];
        $pipesList = [];
        for ($i = 0; $i < 100; $i++) {
            $cmd = [PHP_BINARY, $worker, getenv('TRUNKCAP_TEST_DSN'),
                getenv('TRUNKCAP_TEST_USER') ?: 'root', getenv('TRUNKCAP_TEST_PASS') ?: '', (string) $t, (string) $start, "c$i"];
            $procs[]     = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $pipesList[] = $pipes;
        }
        $allowed = 0;
        $errors  = [];
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($pipesList[$i][1]);
            // ignore PHP startup noise from the local build (e.g. extension load warnings)
            $err = implode("\n", preg_grep('/^(PHP )?(Warning|Notice|Failed loading)/', explode("\n", trim(stream_get_contents($pipesList[$i][2]))), PREG_GREP_INVERT));
            proc_close($procs[$i]);
            $d = json_decode($out, true);
            if (! is_array($d)) {
                $errors[] = trim($out . ' ' . $err);
                continue;
            }
            $allowed += $d['allow'] ? 1 : 0;
            if ($err !== '') {
                $errors[] = trim($err);
            }
        }
        $this->assertSame([], array_slice($errors, 0, 3), 'worker errors');
        $this->assertSame(10, $allowed);
        $open = self::$db->query("SELECT COUNT(*) FROM pkg_trunk_cap_reservation WHERE id_trunk = $t AND released_at IS NULL")->fetchColumn();
        $this->assertSame('10', (string) $open);
    }

    // ---- windows, periods, rolling ------------------------------------------

    public function testPolicyOutsideItsTimeWindowDoesNotLimit()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_calls' => 1, 'window_start' => '18:00:00', 'window_end' => '22:00:00']);
        $s = $this->service();
        $this->assertTrue($s->reserve($t, 'a', 600)['allow']);  // 11:00 Lagos
        $this->assertTrue($s->reserve($t, 'b', 600)['allow']);
        $this->now = (float) gmmktime(18, 0, 0, 10, 13, 2026);   // 19:00 Lagos
        $this->assertTrue($s->reserve($t, 'c', 600)['allow']);
        $this->assertFalse($s->reserve($t, 'd', 600)['allow']);
    }

    public function testCommitAfterPeriodRolloverReleasesTheOriginalWindow()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['period_type' => 'day', 'max_minutes' => 100]);
        $s = $this->service();
        $this->now = (float) gmmktime(22, 50, 0, 10, 13, 2026); // 23:50 Lagos
        $d = $s->reserve($t, 'a', 600);
        $this->now += 1200;                                     // next Lagos day
        $s->commit($d['reservation'], 'ANSWER', 1200);
        $rows = array_map(function ($r) {
            return array_map('strval', $r);
        }, self::$db->query("SELECT window_start, billed_seconds, reserved_seconds, reserved_calls
            FROM pkg_trunk_cap_usage WHERE id_policy = $p")->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([['window_start' => '2026-10-12 23:00:00', 'billed_seconds' => '1200',
            'reserved_seconds' => '0', 'reserved_calls' => '0']], $rows);
    }

    public function testRollingHourForgetsCallsOlderThanSixtyMinutes()
    {
        $t = $this->trunk();
        $this->policy($t, ['period_type' => 'hour', 'anchor' => 'rolling', 'max_calls' => 2]);
        $s = $this->service();
        $s->commit($s->reserve($t, 'a', 600)['reservation'], 'ANSWER', 30);
        $this->now += 1800;
        $s->commit($s->reserve($t, 'b', 600)['reservation'], 'ANSWER', 30);
        $this->now += 1700;
        $this->assertFalse($s->reserve($t, 'c', 600)['allow']); // 58 min after the first call
        $this->now += 120;
        $this->assertTrue($s->reserve($t, 'd', 600)['allow']);  // 60 min later
    }

    public function testMaxCallsPerHourOnAMonthlyPolicy()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_calls' => 1000, 'max_calls_per_hour' => 2]);
        $s = $this->service();
        $s->reserve($t, 'a', 600);
        $s->reserve($t, 'b', 600);
        $this->assertSame('max_calls_per_hour', $s->reserve($t, 'c', 600)['limit']);
        $this->now += 3600;
        $this->assertTrue($s->reserve($t, 'd', 600)['allow']);
    }

    // ---- actions, warnings, failure handling --------------------------------

    public function testRejectActionWinsOverSkip()
    {
        $t = $this->trunk();
        $this->policy($t, ['max_calls' => 1]);
        $this->policy($t, ['max_concurrent' => 1, 'on_limit' => 'reject']);
        $s = $this->service();
        $s->reserve($t, 'a', 600);
        $this->assertSame('reject', $s->reserve($t, 'b', 600)['action']);
    }

    public function testWarningsFireOncePerThreshold()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 10, 'warn_pct' => '50,80']);
        $s = $this->service();
        $sent = [];
        $s->setNotifier(function ($w) use (&$sent) {
            $sent[] = $w['threshold'];
        });
        for ($i = 0; $i < 9; $i++) {
            $s->commit($s->reserve($t, "u$i", 600)['reservation'], 'ANSWER', 10);
        }
        $this->assertSame([50, 80], $sent);
        $this->assertCount(2, $this->events($t, 'warned'));
        $this->assertSame('80', $this->usage($p)['warned_pct']);
    }

    public function testPausedPolicyIsNotEnforcedButStillCounts()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 1, 'max_minutes' => 1]);
        $s = $this->service();
        $s->setOverride($p, $this->now + 3600, 7);

        $first  = $s->reserve($t, 'a', 600);
        $second = $s->reserve($t, 'b', 600);
        $this->assertTrue($first['allow'] && $second['allow']);
        $this->assertSame(600, $second['timeout'], 'no duration cap while paused');
        $s->commit($first['reservation'], 'ANSWER', 120);
        $this->assertSame(['1', '2', '120'], array_values(array_intersect_key($this->usage($p), array_flip(['calls', 'attempts', 'billed_seconds']))));

        $this->now += 3601; // pause over: limits apply again, counting what happened meanwhile
        $this->assertFalse($s->reserve($t, 'c', 600)['allow']);
        $override = $this->events($t, 'override');
        $this->assertSame('7', (string) $override[0]['id_user']);
    }

    public function testResetKeepsInFlightCallsAndAuditsOldUsage()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 10, 'max_minutes' => 100, 'warn_pct' => '10']);
        $s = $this->service();
        $s->commit($s->reserve($t, 'done', 600)['reservation'], 'ANSWER', 700);
        $live = $s->reserve($t, 'live', 600);

        $snapshot = $s->resetWindow($p, 9);
        $this->assertSame(1, $snapshot['before']['calls']);
        $this->assertSame(['0', '1', '1', '0', '0'], array_values(array_intersect_key($this->usage($p),
            array_flip(['calls', 'attempts', 'reserved_calls', 'billed_seconds', 'warned_pct']))));

        $s->commit($live['reservation'], 'ANSWER', 60); // reserved before the reset: no underflow
        $u = $this->usage($p);
        $this->assertSame(['1', '0', '0', '60'], [$u['calls'], $u['reserved_calls'], $u['reserved_seconds'], $u['billed_seconds']]);
        $reset = $this->events($t, 'reset');
        $this->assertSame('9', (string) $reset[0]['id_user']);
    }

    public function testDryRunWritesNothing()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 1]);
        $s = $this->service();
        $d = $s->reserve($t, 'a', 600, true);
        $this->assertTrue($d['allow']);
        $this->assertNull($d['reservation']);
        $this->assertFalse($this->usage($p));
        $this->assertSame('0', $this->countRows("SELECT COUNT(*) FROM pkg_trunk_cap_reservation WHERE id_trunk = $t"));
    }

    public function testDatabaseErrorFailsOpenUnlessStrict()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_calls' => 1]);
        $broken = new TrunkCapFailingPdo(getenv('TRUNKCAP_TEST_DSN'), getenv('TRUNKCAP_TEST_USER') ?: 'root', getenv('TRUNKCAP_TEST_PASS') ?: '');

        $d = $this->service($broken)->reserve($t, 'a', 600);
        $this->assertTrue($d['allow']);
        $this->assertStringContainsString('ERROR Cap check failed', implode("\n", $this->log));
        $this->assertFalse($this->usage($p), 'transaction rolled back');

        self::$db->exec("UPDATE pkg_trunk_cap_policy SET strict = 1 WHERE id = $p");
        $this->assertFalse($this->service($broken)->reserve($t, 'b', 600)['allow']);
    }

    public function testSweepReleasesStaleReservationsUsingCdrTime()
    {
        $t = $this->trunk();
        $p = $this->policy($t, ['max_minutes' => 1000, 'max_concurrent' => 5]);
        $s = $this->service();
        $withCdr = $s->reserve($t, 'crash-1', 600);
        $s->reserve($t, 'crash-2', 600);
        self::$db->exec("INSERT INTO pkg_cdr (id_user, id_plan, id_trunk, uniqueid, starttime, sessiontime, real_sessiontime,
                calledstation, src, sipiax, sessionbill, id_prefix, terminatecauseid)
            VALUES (1, 1, $t, 'crash-1', '2026-10-13 10:00:00', 240, 240, '000', '000', 0, 0, 1, 1)");

        $this->now += 600 + TrunkCapService::RESERVATION_GRACE_SECONDS - 1;
        $this->assertSame(0, $s->sweep()['swept']);
        $this->now += 2;
        $this->assertSame(2, $s->sweep()['swept']);

        $u = $this->usage($p);
        $this->assertSame(['1', '240', '0', '0'], [$u['calls'], $u['billed_seconds'], $u['reserved_seconds'], $u['reserved_calls']]);
        $this->assertCount(2, $this->events($t, 'swept'));
        $this->assertFalse($s->commit($withCdr['reservation'], 'ANSWER', 240), 'late commit after sweep is ignored');

        $this->now += TrunkCapService::KEEP_RELEASED_SECONDS + 10;
        $this->assertSame(2, $s->sweep()['purged_reservations']);
    }

    // ---- seeding new policies from CDR --------------------------------------

    public function testNewPolicyIsSeededFromCdrWithoutCountingAnyCallTwice()
    {
        $t       = $this->trunk();
        $created = (int) $this->now - 3600; // 2026-10-13 09:00 UTC; the month started 2026-09-30 23:00 UTC (Lagos)
        $provider = (int) self::$db->query("SELECT id FROM pkg_provider WHERE provider_name = 'tctest-provider'")->fetchColumn();
        self::$db->exec("DELETE FROM pkg_rate_provider WHERE id_provider = $provider");
        self::$db->exec("INSERT INTO pkg_rate_provider (id_provider, id_prefix, buyrate, buyrateinitblock, buyrateincrement)
            VALUES ($provider, " . $this->prefixId('9997') . ', 0.01, 60, 60)');

        $this->cdr($t, 'old', gmmktime(22, 0, 0, 9, 30, 2026), 30);                         // before the window
        $this->cdr($t, 'a', gmmktime(12, 0, 0, 10, 5, 2026), 61, $this->prefixId('99971')); // 60/60 via the 9997 rate
        $this->failedCdr($t, 'f', gmmktime(12, 0, 0, 10, 6, 2026));
        $this->cdr($t, 'live-1', $created - 10, 50);                                          // reserved after creation
        $this->cdr($t, 'later', $created + 1800, 100);                                        // counted live (not here)

        $p = $this->policy($t, ['max_minutes' => 1000]);
        $this->createdAt($p, $created);
        $s = $this->service();
        $this->now = $created + 1;
        $s->commit($s->reserve($t, 'live-1', 600)['reservation'], 'ANSWER', 50, 60, 60);
        $this->now = $created + 600;

        $snapshot = $s->seedFromCdr($p, 7);
        $this->assertSame(['calls' => 1, 'attempts' => 2, 'billed_seconds' => 120, 'skipped_live' => 1],
            array_intersect_key($snapshot, array_flip(['calls', 'attempts', 'billed_seconds', 'skipped_live'])));
        $this->assertSame('2026-09-30 23:00:00', $snapshot['window_start']);
        $u = $this->usage($p);
        $this->assertSame(['2', '3', '180'], [$u['calls'], $u['attempts'], $u['billed_seconds']]);

        $events = $this->events($t, 'seeded');
        $this->assertCount(1, $events);
        $this->assertSame('7', (string) $events[0]['id_user']);
        $this->assertFalse($s->seedFromCdr($p), 'a policy is seeded once');
        $this->assertSame('180', $this->usage($p)['billed_seconds']);
        self::$db->exec("DELETE FROM pkg_rate_provider WHERE id_provider = $provider");
    }

    public function testSeedingFillsRollingBucketsAndHonoursTheTimeWindow()
    {
        $t       = $this->trunk();
        $created = (int) $this->now;
        $this->cdr($t, 'in', $created - 1200, 30);   // 20 minutes before: inside the rolling hour
        $this->cdr($t, 'out', $created - 4200, 30);  // 70 minutes before
        $rolling = $this->policy($t, ['period_type' => 'hour', 'anchor' => 'rolling', 'max_calls' => 1]);
        $evening = $this->policy($t, ['period_type' => 'day', 'max_calls' => 1, 'window_start' => '18:00:00', 'window_end' => '22:00:00']);
        $this->createdAt($rolling, $created);
        $this->createdAt($evening, $created);
        $s = $this->service();

        $this->assertSame(1, $s->seedFromCdr($rolling)['calls']);
        $this->assertSame('1', $this->countRows("SELECT SUM(calls) FROM pkg_trunk_cap_minute WHERE id_policy = $rolling"));
        $this->assertSame(0, $s->seedFromCdr($evening)['attempts'], 'calls at 11:00 Lagos are outside 18:00-22:00');
        $d = $s->reserve($t, 'next', 600);
        $this->assertFalse($d['allow']);
        $this->assertSame($rolling, $d['id_policy']);
    }

    public function testSeedPendingMarksPoliciesFromEarlierWindowsWithoutCounting()
    {
        $t = $this->trunk();
        $this->cdr($t, 'a', (int) $this->now - 600, 30);
        $p = $this->policy($t, ['max_calls' => 5]);
        $this->createdAt($p, gmmktime(12, 0, 0, 9, 15, 2026)); // last month: the live counter covers this month
        $this->assertGreaterThanOrEqual(1, $this->service()->seedPending());
        $this->assertNotNull(self::$db->query("SELECT cdr_seeded_at FROM pkg_trunk_cap_policy WHERE id = $p")->fetchColumn());
        $this->assertFalse($this->usage($p));
        $this->assertCount(1, $this->events($t, 'seeded'));
    }
}

/** PDO whose trunk lock query fails, to exercise the fail-open path. */
class TrunkCapFailingPdo extends PDO
{
    public function __construct($dsn, $user, $pass)
    {
        parent::__construct($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if (strpos($query, 'pkg_trunk_cap_lock') !== false) {
            throw new PDOException('simulated failure');
        }
        return parent::prepare($query, $options);
    }
}
