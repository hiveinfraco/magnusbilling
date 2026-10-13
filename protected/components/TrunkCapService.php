<?php
/**
 * Trunk cap engine: check, reserve, commit and sweep.
 *
 * Plain PDO, no Yii: the AGI passes its AGI object (which extends PDO), the
 * panel and cron pass Yii::app()->db->pdoInstance.
 *
 * Call lifecycle, per trunk attempt:
 *   reserve()  before Dial. Under a per-trunk row lock: check every active
 *              policy whose time window applies, then charge one attempt,
 *              one in-flight call and the reserved seconds to each policy's
 *              current window, and insert a reservation row.
 *   commit()   after Dial returns. Turns the reservation into real usage
 *              (answered call, billed seconds with provider rounding).
 *   sweep()    cron. Releases reservations whose AGI died, and purges old rows.
 *
 * Fail-safe: any error in reserve() logs loudly and allows the call, unless
 * one of the trunk's active policies is strict.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
require_once __DIR__ . '/TrunkCapWindow.php';
require_once __DIR__ . '/TrunkCapDescriber.php';

class TrunkCapService
{
    /** ASSUMPTION: a minutes cap with less headroom than this blocks the trunk. */
    const MIN_CALL_SECONDS = 30;
    /** ASSUMPTION: expected call length before a window has enough answered calls to average. */
    const DEFAULT_EXPECTED_SECONDS = 180;
    const EXPECTED_MIN_SAMPLE      = 10;
    /** Adaptive reservations switch to full reservations below this many call time limits of headroom. */
    const FULL_RESERVE_FACTOR = 2;
    /** Ring time and slack added to a reservation's maximum duration before the sweeper may release it. */
    const RESERVATION_GRACE_SECONDS = 300;
    const KEEP_RELEASED_SECONDS     = 86400;
    const KEEP_MINUTE_SECONDS       = 2764800; // rolling month (30 days) + 2 days
    /** Transaction attempts on deadlock / lock wait timeout before failing open. */
    const MAX_ATTEMPTS = 3;

    private $db;
    private $log;
    private $clock;
    private $notifier;

    /**
     * @param PDO           $db
     * @param callable|null $log   function (string $message, bool $error)
     * @param callable|null $clock function (): float  UTC epoch seconds; for tests
     */
    public function __construct(PDO $db, $log = null, $clock = null)
    {
        $this->db    = $db;
        $this->log   = $log;
        $this->clock = $clock;
    }

    /** @param callable $notifier function (array $warning) called when a warning threshold is crossed */
    public function setNotifier($notifier)
    {
        $this->notifier = $notifier;
    }

    /**
     * Decide whether a call may be sent to the trunk and, if so, reserve capacity.
     *
     * @param int    $timeout seconds the call would otherwise be allowed (Dial L() limit)
     * @param bool   $dryRun  check only: no lock, no writes (Call Diagnostics)
     * @return array allow (bool), action (allow|skip_trunk|reject), reservation (int|null),
     *               timeout (int, possibly lowered), reason, id_policy, limit, snapshot
     */
    public function reserve($idTrunk, $uniqueid, $timeout, $dryRun = false)
    {
        $idTrunk  = (int) $idTrunk;
        $timeout  = max(1, (int) $timeout);
        $policies = null;

        try {
            $policies = $this->activePolicies($idTrunk);
            if (! $policies) {
                return $this->allowDecision($timeout);
            }
            if ($dryRun) {
                $result = $this->evaluate($idTrunk, $policies, $timeout, $this->now());
                return $result['decision'];
            }

            $this->ensureLockRow($idTrunk);
            for ($attempt = 1;; $attempt++) {
                try {
                    $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    $this->db->beginTransaction();
                    $this->lockTrunk($idTrunk);
                    $now      = $this->now();
                    $result   = $this->evaluate($idTrunk, $policies, $timeout, $now);
                    $decision = $result['decision'];
                    if ($decision['allow']) {
                        $decision['reservation'] = $this->writeReservation(
                            $idTrunk, $uniqueid, $now, $decision['timeout'], $result['allocations']
                        );
                    }
                    $this->db->commit();
                    break;
                } catch (PDOException $e) {
                    $this->rollback();
                    if ($attempt >= self::MAX_ATTEMPTS || ! self::isRetryable($e)) {
                        throw $e;
                    }
                    $this->say('Retrying cap check for trunk ' . $idTrunk . ' after: ' . $e->getMessage());
                    usleep(mt_rand(1000, 5000) * $attempt);
                }
            }
        } catch (Throwable $e) {
            $this->rollback();
            $this->say('Cap check failed for trunk ' . $idTrunk . ', ' . get_class($e) . ': ' . $e->getMessage(), true);
            if ($policies && $this->anyStrict($policies)) {
                return $this->blockDecision($timeout, 'skip_trunk', 'cap check failed and a policy is strict', null, null, []);
            }
            return $this->allowDecision($timeout);
        }

        if (! $decision['allow']) {
            $this->logEvent($idTrunk, $decision['id_policy'], 'blocked', $decision['snapshot'], $uniqueid);
        }
        return $decision;
    }

    /**
     * Turn a reservation into real usage once Dial has returned.
     *
     * @param int      $answeredSeconds real answered time (0 if not answered)
     * @param int|null $providerInit    provider buy-rate initial block, used when the policy has no override
     * @param int|null $providerInc     provider buy-rate increment, idem
     * @return bool false if the reservation was already released (e.g. swept) or on error
     */
    public function commit($reservationId, $dialstatus, $answeredSeconds, $providerInit = null, $providerInc = null)
    {
        $reservationId   = (int) $reservationId;
        $answeredSeconds = max(0, (int) $answeredSeconds);

        try {
            $idTrunk = $this->scalar('SELECT id_trunk FROM pkg_trunk_cap_reservation WHERE id = ?', [$reservationId]);
            if ($idTrunk === false) {
                return false;
            }

            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->beginTransaction();
            $this->lockTrunk((int) $idTrunk);
            $reservation = $this->row(
                'SELECT * FROM pkg_trunk_cap_reservation WHERE id = ? AND released_at IS NULL FOR UPDATE',
                [$reservationId]
            );
            if (! $reservation) {
                $this->db->commit();
                return false;
            }

            $allocations = $this->decodeAllocations($reservation['allocations']);
            $rounding    = $this->policyRounding($allocations);
            $answered    = $answeredSeconds > 0 ? 1 : 0;

            foreach ($allocations as $a) {
                $init = isset($rounding[$a['p']]['init']) ? $rounding[$a['p']]['init'] : $providerInit;
                $inc  = isset($rounding[$a['p']]['inc']) ? $rounding[$a['p']]['inc'] : $providerInc;
                $billed = self::billedSeconds($answeredSeconds, $init, $inc);
                $params = [$answered, $billed, (int) $a['s'], (int) $a['s']];
                if ($a['w'] !== null) {
                    $this->exec(
                        'UPDATE pkg_trunk_cap_usage SET ' . self::RELEASE_SET . ' WHERE id_policy = ? AND window_start = ?',
                        array_merge($params, [(int) $a['p'], $a['w']])
                    );
                }
                if ($a['m'] !== null) {
                    $this->exec(
                        'UPDATE pkg_trunk_cap_minute SET ' . self::RELEASE_SET . ' WHERE id_policy = ? AND minute_start = ?',
                        array_merge($params, [(int) $a['p'], $a['m']])
                    );
                }
            }

            $this->exec(
                'UPDATE pkg_trunk_cap_reservation SET released_at = ?, dialstatus = ? WHERE id = ?',
                [TrunkCapWindow::toDb($this->now()), substr((string) $dialstatus, 0, 20), $reservationId]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->rollback();
            $this->say('Cap commit failed for reservation ' . $reservationId . ': ' . $e->getMessage(), true);
            return false;
        }

        $this->checkWarnings((int) $reservation['id_trunk'], $allocations, $reservation['uniqueid']);
        return true;
    }

    /** Applied on commit: params are answered (0|1), billed seconds, reserved seconds (twice). */
    const RELEASE_SET = 'calls = calls + ?, billed_seconds = billed_seconds + ?,
        reserved_calls = IF(reserved_calls > 0, reserved_calls - 1, 0),
        reserved_seconds = IF(reserved_seconds >= ?, reserved_seconds - ?, 0)';

    /**
     * Release reservations whose AGI process died, then purge old rows.
     * The answered time is taken from pkg_cdr when the call was recorded;
     * otherwise the call is counted as an unanswered attempt.
     *
     * @return array counts: swept, purged_reservations, purged_minutes
     */
    public function sweep()
    {
        $now   = $this->now();
        $stale = $this->rows(
            'SELECT id, id_trunk, uniqueid, created_at FROM pkg_trunk_cap_reservation
             WHERE released_at IS NULL AND expires_at < ? ORDER BY id LIMIT 500',
            [TrunkCapWindow::toDb($now)]
        );

        $swept = 0;
        foreach ($stale as $r) {
            // pkg_cdr.uniqueid is not indexed; bound the lookup by the indexed id_trunk/starttime.
            $answered = $this->scalar(
                'SELECT real_sessiontime FROM pkg_cdr WHERE id_trunk = ? AND starttime >= ? AND uniqueid = ? LIMIT 1',
                [(int) $r['id_trunk'], gmdate('Y-m-d H:i:s', strtotime($r['created_at'] . ' UTC') - 86400), $r['uniqueid']]
            );
            if ($this->commit($r['id'], 'SWEPT', $answered === false ? 0 : (int) $answered)) {
                $swept++;
                $this->logEvent((int) $r['id_trunk'], null, 'swept', [
                    'reservation' => (int) $r['id'],
                    'cdr_seconds' => $answered === false ? null : (int) $answered,
                ], $r['uniqueid']);
            }
        }

        $purgedReservations = $this->exec(
            'DELETE FROM pkg_trunk_cap_reservation WHERE released_at IS NOT NULL AND released_at < ? LIMIT 10000',
            [TrunkCapWindow::toDb($now - self::KEEP_RELEASED_SECONDS)]
        );
        $purgedMinutes = $this->exec(
            'DELETE FROM pkg_trunk_cap_minute WHERE minute_start < ? AND reserved_calls = 0 LIMIT 10000',
            [TrunkCapWindow::toDb($now - self::KEEP_MINUTE_SECONDS)]
        );

        return ['swept' => $swept, 'purged_reservations' => $purgedReservations, 'purged_minutes' => $purgedMinutes];
    }

    /**
     * Provider-rounded billed seconds; mirrors CalcAgi's buycost rounding.
     */
    public static function billedSeconds($seconds, $init = null, $inc = null)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            return 0;
        }
        $init = (int) $init;
        $inc  = (int) $inc;
        if ($seconds < $init) {
            $seconds = $init;
        }
        if ($inc > 0 && $seconds > $init && $seconds % $inc > 0) {
            $seconds += $inc - $seconds % $inc;
        }
        return $seconds;
    }

    /** Effective count limit after the safety margin; never below 1. */
    public static function effectiveCount($limit, $marginPct)
    {
        return max(1, (int) floor((int) $limit * (int) $marginPct / 100));
    }

    // ---- panel: live status, reset, override ---------------------------------

    /**
     * Current usage of every active policy (optionally one trunk), for the
     * live usage view. Read-only.
     *
     * @return array one row per policy
     */
    public function status($idTrunk = null)
    {
        $now      = $this->now();
        $ts       = (int) floor($now);
        $policies = $this->rows(
            'SELECT p.*, t.trunkcode FROM pkg_trunk_cap_policy p JOIN pkg_trunk t ON t.id = p.id_trunk
             WHERE p.active = 1' . ($idTrunk ? ' AND p.id_trunk = ' . (int) $idTrunk : '') . ' ORDER BY t.trunkcode, p.id'
        );

        $trunkStats = [];
        $rows       = [];
        foreach ($policies as $p) {
            $t = (int) $p['id_trunk'];
            if (! isset($trunkStats[$t])) {
                $trunkStats[$t] = [
                    'in_flight' => (int) $this->scalar(
                        'SELECT COUNT(*) FROM pkg_trunk_cap_reservation WHERE id_trunk = ? AND released_at IS NULL', [$t]
                    ),
                ];
            }
            $period = TrunkCapWindow::period($p, $ts);
            $usage  = $this->usage($p, $period);
            $calls  = self::usedCalls($usage, (int) $p['count_failed_attempts'] === 1);

            $pct = null;
            if ($p['max_calls'] !== null) {
                $pct = 100 * $calls / (int) $p['max_calls'];
            }
            if ($p['max_minutes'] !== null) {
                $pct = max((float) $pct, 100 * ($usage['billed_seconds'] + $usage['reserved_seconds']) / ((int) $p['max_minutes'] * 60));
            }
            if ($p['max_concurrent'] !== null) {
                $pct = max((float) $pct, 100 * $trunkStats[$t]['in_flight'] / (int) $p['max_concurrent']);
            }

            if (self::isPaused($p, $ts)) {
                $state = 'paused';
            } elseif (! TrunkCapWindow::appliesAt($p, $ts)) {
                $state = 'outside_window';
            } elseif ($pct !== null && $pct >= (int) $p['safety_margin_pct']) {
                $state = 'blocking';
            } else {
                $warn  = array_filter(array_map('intval', explode(',', (string) $p['warn_pct'])));
                $state = $warn && $pct !== null && $pct >= min($warn) ? 'warning' : 'ok';
            }

            $rows[] = [
                'id'                => (int) $p['id'],
                'id_trunk'          => $t,
                'trunkcode'         => $p['trunkcode'],
                'name'              => $p['name'],
                'summary'           => TrunkCapDescriber::describe($p),
                'state'             => $state,
                'window_start'      => self::localTime($period['start'], $p['timezone']),
                'window_end'        => $period['rolling'] ? null : self::localTime($period['end'], $p['timezone']),
                'rolling'           => $period['rolling'],
                'calls'             => $calls,
                'max_calls'         => $p['max_calls'] === null ? null : (int) $p['max_calls'],
                'billed_minutes'    => round($usage['billed_seconds'] / 60, 1),
                'reserved_minutes'  => round($usage['reserved_seconds'] / 60, 1),
                'max_minutes'       => $p['max_minutes'] === null ? null : (int) $p['max_minutes'],
                'in_flight'         => $trunkStats[$t]['in_flight'],
                'max_concurrent'    => $p['max_concurrent'] === null ? null : (int) $p['max_concurrent'],
                'pct'               => $pct === null ? null : round($pct, 1),
                'safety_margin_pct' => (int) $p['safety_margin_pct'],
                'override_until'    => $p['override_until'],
            ];
        }
        return $rows;
    }

    /**
     * Zero the current window's counters for a policy. In-flight calls keep
     * their reservations. Audited as a 'reset' event with the old usage.
     */
    public function resetWindow($idPolicy, $idUser = null)
    {
        $p = $this->row('SELECT * FROM pkg_trunk_cap_policy WHERE id = ?', [(int) $idPolicy]);
        if (! $p) {
            throw new InvalidArgumentException('Unknown policy ' . (int) $idPolicy);
        }
        $idTrunk = (int) $p['id_trunk'];
        $this->ensureLockRow($idTrunk);

        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->beginTransaction();
            $this->lockTrunk($idTrunk);
            $period = TrunkCapWindow::period($p, (int) floor($this->now()));
            $before = $this->usage($p, $period);
            $zero   = 'calls = 0, attempts = reserved_calls, billed_seconds = 0';
            if ($period['rolling']) {
                $this->exec(
                    "UPDATE pkg_trunk_cap_minute SET $zero WHERE id_policy = ? AND minute_start >= ? AND minute_start < ?",
                    [(int) $p['id'], TrunkCapWindow::toDb($period['start']), TrunkCapWindow::toDb($period['end'])]
                );
            } else {
                $this->exec(
                    "UPDATE pkg_trunk_cap_usage SET $zero, warned_pct = 0 WHERE id_policy = ? AND window_start = ?",
                    [(int) $p['id'], TrunkCapWindow::toDb($period['start'])]
                );
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $snapshot = ['window_start' => TrunkCapWindow::toDb($period['start']), 'before' => $before];
        $this->logEvent($idTrunk, (int) $p['id'], 'reset', $snapshot, null, $idUser);
        return $snapshot;
    }

    /**
     * Pause a policy until $untilTs (UTC epoch), or end a pause with null.
     * Audited as an 'override' event.
     */
    public function setOverride($idPolicy, $untilTs, $idUser = null)
    {
        $p = $this->row('SELECT id, id_trunk, override_until FROM pkg_trunk_cap_policy WHERE id = ?', [(int) $idPolicy]);
        if (! $p) {
            throw new InvalidArgumentException('Unknown policy ' . (int) $idPolicy);
        }
        $until = $untilTs === null ? null : TrunkCapWindow::toDb($untilTs);
        $this->exec('UPDATE pkg_trunk_cap_policy SET override_until = ? WHERE id = ?', [$until, (int) $idPolicy]);
        $this->logEvent((int) $p['id_trunk'], (int) $p['id'], 'override',
            ['override_until' => $until, 'previous' => $p['override_until']], null, $idUser);
    }

    private static function isPaused(array $p, $ts)
    {
        return ! empty($p['override_until']) && TrunkCapWindow::fromDb($p['override_until']) > $ts;
    }

    private static function localTime($ts, $timezone)
    {
        return (new DateTimeImmutable('@' . (int) $ts))->setTimezone(new DateTimeZone($timezone ?: 'Africa/Lagos'))
            ->format('Y-m-d H:i T');
    }

    // ---- evaluation ---------------------------------------------------------

    private function evaluate($idTrunk, array $policies, $timeout, $now)
    {
        $ts          = (int) floor($now);
        $minute      = TrunkCapWindow::minuteStart($ts);
        $trunkStats  = [];
        $timeLimit   = $timeout;
        $blocks      = [];
        $allocations = [];

        foreach ($policies as $p) {
            if (! TrunkCapWindow::appliesAt($p, $ts)) {
                continue;
            }
            // A paused policy is not enforced, but its usage is still counted so the
            // counters keep matching the provider after the pause ends.
            $paused   = self::isPaused($p, $ts);
            $period   = TrunkCapWindow::period($p, $ts);
            $usage    = $this->usage($p, $period);
            $margin   = (int) $p['safety_margin_pct'];
            $attempts = (int) $p['count_failed_attempts'] === 1;
            $window   = TrunkCapWindow::toDb($period['start']);

            $countChecks = [
                'max_calls' => function () use ($usage, $attempts) {
                    return self::usedCalls($usage, $attempts);
                },
                'max_calls_per_hour' => function () use ($p, $minute, $attempts) {
                    return self::usedCalls($this->minuteSums($p['id'], $minute - 3540, $minute + 60), $attempts);
                },
                'max_concurrent' => function () use ($idTrunk, &$trunkStats) {
                    if (! isset($trunkStats['open'])) {
                        $trunkStats['open'] = (int) $this->scalar(
                            'SELECT COUNT(*) FROM pkg_trunk_cap_reservation WHERE id_trunk = ? AND released_at IS NULL',
                            [$idTrunk]
                        );
                    }
                    return $trunkStats['open'];
                },
                'max_cps' => function () use ($idTrunk, $now, &$trunkStats) {
                    if (! isset($trunkStats['cps'])) {
                        $trunkStats['cps'] = (int) $this->scalar(
                            'SELECT COUNT(*) FROM pkg_trunk_cap_reservation WHERE id_trunk = ? AND created_at > ?',
                            [$idTrunk, self::toDbMs($now - 1)]
                        );
                    }
                    return $trunkStats['cps'];
                },
            ];
            foreach ($countChecks as $limit => $used) {
                if ($paused || $p[$limit] === null || $p[$limit] === '') {
                    continue;
                }
                // The margin covers drift between our period counters and the provider's records.
                // Concurrency and CPS are measured exactly here, so they use the provider limit as is.
                $effective = in_array($limit, ['max_concurrent', 'max_cps'], true)
                    ? (int) $p[$limit]
                    : self::effectiveCount($p[$limit], $margin);
                $value     = $used();
                if ($value + 1 > $effective) {
                    $blocks[] = [$p, $limit, ['used' => $value, 'limit' => (int) $p[$limit], 'effective' => $effective, 'window_start' => $window]];
                }
            }

            $reserve = 0;
            if (! $paused && $p['max_minutes'] !== null && $p['max_minutes'] !== '') {
                $effective = (int) floor((int) $p['max_minutes'] * 60 * $margin / 100);
                $available = $effective - $usage['billed_seconds'] - $usage['reserved_seconds'];
                if ($available < self::MIN_CALL_SECONDS) {
                    $blocks[] = [$p, 'max_minutes', [
                        'used_seconds' => $usage['billed_seconds'] + $usage['reserved_seconds'],
                        'billed_seconds' => $usage['billed_seconds'],
                        'limit' => (int) $p['max_minutes'] * 60, 'effective' => $effective, 'window_start' => $window,
                    ]];
                } else {
                    $callLimit = min($timeout, $available);
                    $timeLimit = min($timeLimit, $callLimit);
                    $full      = $p['reserve_mode'] === 'full' || $available < self::FULL_RESERVE_FACTOR * $timeout;
                    $reserve   = $full ? $callLimit : min($callLimit, self::expectedSeconds($usage));
                }
            }

            $allocations[] = [
                'p' => (int) $p['id'],
                'w' => $period['rolling'] ? null : $window,
                'm' => ($period['rolling'] || ($p['max_calls_per_hour'] !== null && $p['max_calls_per_hour'] !== ''))
                    ? TrunkCapWindow::toDb($minute) : null,
                's' => $reserve,
            ];
        }

        if ($blocks) {
            $action = 'skip_trunk';
            foreach ($blocks as $b) {
                if ($b[0]['on_limit'] === 'reject') {
                    $action = 'reject';
                }
            }
            list($p, $limit, $snapshot) = $blocks[0];
            $snapshot = ['limit_type' => $limit] + $snapshot;
            $reason = sprintf('%s reached on policy "%s"', $limit, $p['name']);
            return ['decision' => $this->blockDecision($timeout, $action, $reason, (int) $p['id'], $limit, $snapshot), 'allocations' => []];
        }

        foreach ($allocations as &$a) {
            $a['s'] = min($a['s'], $timeLimit);
        }
        unset($a);

        $decision            = $this->allowDecision($timeLimit);
        $decision['limited'] = $timeLimit < $timeout;
        return ['decision' => $decision, 'allocations' => $allocations];
    }

    private static function usedCalls(array $usage, $countAttempts)
    {
        // attempts are charged at reserve time, so they already include in-flight calls
        return $countAttempts ? $usage['attempts'] : $usage['calls'] + $usage['reserved_calls'];
    }

    private static function expectedSeconds(array $usage)
    {
        if ($usage['calls'] >= self::EXPECTED_MIN_SAMPLE) {
            return (int) ceil($usage['billed_seconds'] / $usage['calls'] / 60) * 60;
        }
        return self::DEFAULT_EXPECTED_SECONDS;
    }

    private function usage(array $p, array $period)
    {
        if ($period['rolling']) {
            return $this->minuteSums($p['id'], $period['start'], $period['end']);
        }
        $row = $this->row(
            'SELECT calls, attempts, reserved_calls, billed_seconds, reserved_seconds
             FROM pkg_trunk_cap_usage WHERE id_policy = ? AND window_start = ?',
            [(int) $p['id'], TrunkCapWindow::toDb($period['start'])]
        );
        return self::intRow($row);
    }

    private function minuteSums($idPolicy, $start, $end)
    {
        $row = $this->row(
            'SELECT SUM(calls) calls, SUM(attempts) attempts, SUM(reserved_calls) reserved_calls,
                    SUM(billed_seconds) billed_seconds, SUM(reserved_seconds) reserved_seconds
             FROM pkg_trunk_cap_minute WHERE id_policy = ? AND minute_start >= ? AND minute_start < ?',
            [(int) $idPolicy, TrunkCapWindow::toDb($start), TrunkCapWindow::toDb($end)]
        );
        return self::intRow($row);
    }

    private static function intRow($row)
    {
        $out = [];
        foreach (['calls', 'attempts', 'reserved_calls', 'billed_seconds', 'reserved_seconds'] as $k) {
            $out[$k] = $row && $row[$k] !== null ? (int) $row[$k] : 0;
        }
        return $out;
    }

    // ---- writes -------------------------------------------------------------

    private function writeReservation($idTrunk, $uniqueid, $now, $timeLimit, array $allocations)
    {
        $charge = ' (id_policy, %s, attempts, reserved_calls, reserved_seconds) VALUES (?, ?, 1, 1, ?)
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, reserved_calls = reserved_calls + 1,
            reserved_seconds = reserved_seconds + VALUES(reserved_seconds)';
        $expected = 0;
        foreach ($allocations as $a) {
            if ($a['w'] !== null) {
                $this->exec('INSERT INTO pkg_trunk_cap_usage' . sprintf($charge, 'window_start'), [$a['p'], $a['w'], $a['s']]);
            }
            if ($a['m'] !== null) {
                $this->exec('INSERT INTO pkg_trunk_cap_minute' . sprintf($charge, 'minute_start'), [$a['p'], $a['m'], $a['s']]);
            }
            $expected = max($expected, $a['s']);
        }

        $this->exec(
            'INSERT INTO pkg_trunk_cap_reservation
                (id_trunk, uniqueid, expected_seconds, max_seconds, allocations, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $idTrunk, substr((string) $uniqueid, 0, 50), $expected, $timeLimit, json_encode($allocations),
                self::toDbMs($now), TrunkCapWindow::toDb($now + $timeLimit + self::RESERVATION_GRACE_SECONDS),
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    private function checkWarnings($idTrunk, array $allocations, $uniqueid)
    {
        try {
            foreach ($allocations as $a) {
                if ($a['w'] === null) {
                    continue; // ASSUMPTION: no warnings for rolling windows (no per-window row to remember them)
                }
                $row = $this->row(
                    'SELECT p.id, p.name, p.warn_pct, p.max_calls, p.max_minutes, p.count_failed_attempts,
                            u.calls, u.attempts, u.reserved_calls, u.billed_seconds, u.reserved_seconds, u.warned_pct
                     FROM pkg_trunk_cap_usage u JOIN pkg_trunk_cap_policy p ON p.id = u.id_policy
                     WHERE u.id_policy = ? AND u.window_start = ?',
                    [(int) $a['p'], $a['w']]
                );
                if (! $row) {
                    continue;
                }
                // Percent of the provider's cap (not the margin-adjusted limit), billed usage only.
                $pct = 0;
                if ($row['max_calls'] !== null) {
                    $used = (int) $row['count_failed_attempts'] === 1 ? (int) $row['attempts'] : (int) $row['calls'];
                    $pct  = max($pct, 100 * $used / (int) $row['max_calls']);
                }
                if ($row['max_minutes'] !== null) {
                    $pct = max($pct, 100 * (int) $row['billed_seconds'] / ((int) $row['max_minutes'] * 60));
                }
                $crossed = 0;
                foreach (array_map('intval', explode(',', (string) $row['warn_pct'])) as $threshold) {
                    if ($threshold > (int) $row['warned_pct'] && $pct >= $threshold) {
                        $crossed = max($crossed, $threshold);
                    }
                }
                if ($crossed === 0) {
                    continue;
                }
                $updated = $this->exec(
                    'UPDATE pkg_trunk_cap_usage SET warned_pct = ? WHERE id_policy = ? AND window_start = ? AND warned_pct < ?',
                    [$crossed, (int) $a['p'], $a['w'], $crossed]
                );
                if ($updated !== 1) {
                    continue; // another call already sent this warning
                }
                $warning = [
                    'id_trunk' => $idTrunk, 'id_policy' => (int) $row['id'], 'policy' => $row['name'],
                    'threshold' => $crossed, 'pct' => round($pct, 1), 'window_start' => $a['w'],
                    'calls' => (int) $row['calls'], 'attempts' => (int) $row['attempts'],
                    'billed_seconds' => (int) $row['billed_seconds'],
                ];
                $this->logEvent($idTrunk, (int) $row['id'], 'warned', $warning, $uniqueid);
                if ($this->notifier) {
                    call_user_func($this->notifier, $warning);
                }
            }
        } catch (Throwable $e) {
            $this->say('Cap warning check failed: ' . $e->getMessage(), true);
        }
    }

    public function logEvent($idTrunk, $idPolicy, $event, array $snapshot = [], $uniqueid = null, $idUser = null)
    {
        try {
            $this->exec(
                'INSERT INTO pkg_trunk_cap_event (created_at, id_trunk, id_policy, event, snapshot, uniqueid, id_user)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [TrunkCapWindow::toDb($this->now()), (int) $idTrunk, $idPolicy, $event, json_encode($snapshot), $uniqueid, $idUser]
            );
        } catch (Throwable $e) {
            $this->say('Cap event log failed: ' . $e->getMessage(), true);
        }
    }

    // ---- helpers ------------------------------------------------------------

    private function activePolicies($idTrunk)
    {
        return $this->rows('SELECT * FROM pkg_trunk_cap_policy WHERE id_trunk = ? AND active = 1 ORDER BY id', [$idTrunk]);
    }

    /**
     * The per-trunk lock row must exist before the transaction: creating it
     * inside concurrent transactions deadlocks on the duplicate-key check.
     */
    private function ensureLockRow($idTrunk)
    {
        if ($this->scalar('SELECT id_trunk FROM pkg_trunk_cap_lock WHERE id_trunk = ?', [$idTrunk]) === false) {
            $this->exec('INSERT IGNORE INTO pkg_trunk_cap_lock (id_trunk) VALUES (?)', [$idTrunk]);
        }
    }

    private function lockTrunk($idTrunk)
    {
        if ($this->scalar('SELECT id_trunk FROM pkg_trunk_cap_lock WHERE id_trunk = ? FOR UPDATE', [$idTrunk]) === false) {
            throw new RuntimeException('Trunk lock row missing for trunk ' . $idTrunk);
        }
    }

    private static function isRetryable(PDOException $e)
    {
        // 1213 deadlock, 1205 lock wait timeout
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        return $code === 1213 || $code === 1205;
    }

    private function policyRounding(array $allocations)
    {
        $ids = array_unique(array_map(function ($a) {
            return (int) $a['p'];
        }, $allocations));
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach ($this->rows(
            'SELECT id, rounding_init_sec, rounding_inc_sec FROM pkg_trunk_cap_policy WHERE id IN (' . implode(',', $ids) . ')'
        ) as $r) {
            $out[(int) $r['id']] = [
                'init' => $r['rounding_init_sec'] === null ? null : (int) $r['rounding_init_sec'],
                'inc'  => $r['rounding_inc_sec'] === null ? null : (int) $r['rounding_inc_sec'],
            ];
        }
        return $out;
    }

    private function decodeAllocations($json)
    {
        $allocations = json_decode((string) $json, true);
        return is_array($allocations) ? $allocations : [];
    }

    private function anyStrict(array $policies)
    {
        foreach ($policies as $p) {
            if ((int) $p['strict'] === 1) {
                return true;
            }
        }
        return false;
    }

    private function allowDecision($timeout)
    {
        return ['allow' => true, 'action' => 'allow', 'reservation' => null, 'timeout' => (int) $timeout,
            'limited' => false, 'reason' => '', 'id_policy' => null, 'limit' => null, 'snapshot' => []];
    }

    private function blockDecision($timeout, $action, $reason, $idPolicy, $limit, array $snapshot)
    {
        return ['allow' => false, 'action' => $action, 'reservation' => null, 'timeout' => (int) $timeout,
            'limited' => false, 'reason' => $reason, 'id_policy' => $idPolicy, 'limit' => $limit, 'snapshot' => $snapshot];
    }

    private function now()
    {
        return $this->clock ? (float) call_user_func($this->clock) : microtime(true);
    }

    private static function toDbMs($ts)
    {
        $whole = (int) floor($ts);
        return gmdate('Y-m-d H:i:s', $whole) . sprintf('.%03d', (int) floor(($ts - $whole) * 1000));
    }

    private function statement($sql, array $params)
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false || $stmt->execute(array_values($params)) === false) {
            $info = $stmt ? $stmt->errorInfo() : $this->db->errorInfo();
            throw new RuntimeException('SQL failed: ' . (isset($info[2]) ? $info[2] : 'unknown error'));
        }
        return $stmt;
    }

    private function exec($sql, array $params = [])
    {
        return $this->statement($sql, $params)->rowCount();
    }

    private function scalar($sql, array $params = [])
    {
        return $this->statement($sql, $params)->fetchColumn();
    }

    private function row($sql, array $params = [])
    {
        return $this->statement($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }

    private function rows($sql, array $params = [])
    {
        return $this->statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rollback()
    {
        try {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        } catch (Throwable $e) {
            // connection already gone; nothing left to undo
        }
    }

    private function say($message, $error = false)
    {
        if ($error) {
            error_log('[TrunkCaps] ' . $message);
        }
        if ($this->log) {
            call_user_func($this->log, $message, $error);
        }
    }
}
