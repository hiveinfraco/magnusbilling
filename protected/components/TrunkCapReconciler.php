<?php
/**
 * Trunk cap engine: reconciliation of our usage against provider statements.
 *
 * Plain PDO, no Yii. Compares, per trunk and period:
 *   - CDR: answered calls and their duration (pkg_cdr.real_sessiontime), also
 *     rounded the way the provider bills (policy override, otherwise the
 *     provider buy-rate initblock/increment of the call's prefix, the same
 *     formula CalcAgi uses for buycost);
 *   - the cap counter of a calendar-month minutes policy, when the period is
 *     exactly that month;
 *   - the provider statement (pkg_trunk_cap_statement).
 *
 * CDR times are written in the server's PHP timezone (php.ini date.timezone,
 * set from the system timezone by install.sh), so period bounds are converted
 * to that zone before querying pkg_cdr.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
require_once __DIR__ . '/TrunkCapWindow.php';

class TrunkCapReconciler
{
    private $db;
    private $cdrTimezone;

    /**
     * @param string|null $cdrTimezone zone of pkg_cdr.starttime; default: PHP's default timezone
     */
    public function __construct(PDO $db, $cdrTimezone = null)
    {
        $this->db          = $db;
        $this->cdrTimezone = $cdrTimezone ?: date_default_timezone_get();
    }

    /**
     * Our figures for one trunk over [startDate, endDate] (inclusive local dates).
     *
     * @return array calls, real_seconds, billed_seconds, failed_attempts, counter_seconds (null if no matching counter)
     */
    public function ourUsage($idTrunk, $startDate, $endDate, $timezone)
    {
        list($from, $to) = $this->cdrBounds($startDate, $endDate, $timezone);
        $override         = $this->policyRounding($idTrunk);

        // Per-call rounding in SQL: GREATEST(d, init), then up to a multiple of inc when past init.
        $init = $override ? (int) $override['init'] : 'COALESCE(rp.buyrateinitblock, 0)';
        $inc  = $override ? (int) $override['inc'] : 'COALESCE(rp.buyrateincrement, 0)';
        $sql  = "SELECT COUNT(*) calls, COALESCE(SUM(x.d), 0) real_seconds,
                    COALESCE(SUM(CASE WHEN x.inc > 0 AND x.b > x.init AND MOD(x.b, x.inc) > 0
                        THEN x.b + x.inc - MOD(x.b, x.inc) ELSE x.b END), 0) billed_seconds
                 FROM (
                    SELECT c.real_sessiontime d, $init init, $inc inc, GREATEST(c.real_sessiontime, $init) b
                    FROM pkg_cdr c
                    JOIN pkg_trunk t ON t.id = c.id_trunk
                    LEFT JOIN pkg_rate_provider rp ON rp.id_provider = t.id_provider AND rp.id_prefix = c.id_prefix
                    WHERE c.id_trunk = ? AND c.starttime >= ? AND c.starttime < ? AND c.real_sessiontime > 0
                 ) x";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int) $idTrunk, $from, $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM pkg_cdr_failed WHERE id_trunk = ? AND starttime >= ? AND starttime < ?'
        );
        $stmt->execute([(int) $idTrunk, $from, $to]);

        return [
            'calls'           => (int) $row['calls'],
            'real_seconds'    => (int) $row['real_seconds'],
            'billed_seconds'  => (int) $row['billed_seconds'],
            'failed_attempts' => (int) $stmt->fetchColumn(),
            'counter_seconds' => $this->monthCounter($idTrunk, $startDate, $endDate, $timezone),
        ];
    }

    /**
     * Compare a statement row (pkg_trunk_cap_statement) with our usage.
     *
     * @return array the statement plus our_* figures, diff_pct and status (ok|mismatch)
     */
    public function compare(array $statement, $tolerancePct)
    {
        $ours = $this->ourUsage($statement['id_trunk'], $statement['period_start'], $statement['period_end'], $statement['timezone']);
        return self::row($statement, $ours, $tolerancePct);
    }

    /**
     * Report for a calendar month: every statement starting in that month,
     * plus every trunk with an active minutes policy that has no statement
     * (status no_statement), so missing statements are visible too.
     *
     * @param string $month YYYY-MM
     */
    public function monthly($month, $tolerancePct, $timezone = 'Africa/Lagos')
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Month must be YYYY-MM');
        }
        $first = $month . '-01';
        $last  = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');

        $stmt = $this->db->prepare(
            'SELECT s.*, t.trunkcode FROM pkg_trunk_cap_statement s JOIN pkg_trunk t ON t.id = s.id_trunk
             WHERE s.period_start BETWEEN ? AND ? ORDER BY t.trunkcode, s.period_start'
        );
        $stmt->execute([$first, $last]);
        $rows   = [];
        $seen   = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $rows[]                  = $this->compare($s, $tolerancePct);
            $seen[(int) $s['id_trunk']] = true;
        }

        $trunks = $this->db->query(
            "SELECT DISTINCT t.id, t.trunkcode, p.timezone FROM pkg_trunk_cap_policy p JOIN pkg_trunk t ON t.id = p.id_trunk
             WHERE p.active = 1 AND p.max_minutes IS NOT NULL ORDER BY t.trunkcode"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($trunks as $t) {
            if (isset($seen[(int) $t['id']])) {
                continue;
            }
            $seen[(int) $t['id']] = true;
            $tz     = $t['timezone'] ?: $timezone;
            $rows[] = self::row([
                'id' => null, 'id_trunk' => (int) $t['id'], 'trunkcode' => $t['trunkcode'], 'period_start' => $first,
                'period_end' => $last, 'timezone' => $tz, 'provider_minutes' => null, 'provider_calls' => null, 'note' => '',
            ], $this->ourUsage($t['id'], $first, $last, $tz), $tolerancePct);
        }
        return $rows;
    }

    public static function row(array $statement, array $ours, $tolerancePct)
    {
        $ourMinutes      = round($ours['billed_seconds'] / 60, 2);
        $providerMinutes = $statement['provider_minutes'] === null || $statement['provider_minutes'] === ''
            ? null : (float) $statement['provider_minutes'];

        if ($providerMinutes === null) {
            $diffPct = null;
            $status  = 'no_statement';
        } else {
            $diffPct = $providerMinutes > 0
                ? round(100 * ($ourMinutes - $providerMinutes) / $providerMinutes, 2)
                : ($ourMinutes > 0 ? 100.0 : 0.0);
            $status = abs($diffPct) <= (float) $tolerancePct ? 'ok' : 'mismatch';
        }

        return array_merge($statement, [
            'our_minutes'      => $ourMinutes,
            'our_real_minutes' => round($ours['real_seconds'] / 60, 2),
            'our_calls'        => $ours['calls'],
            'failed_attempts'  => $ours['failed_attempts'],
            'counter_minutes'  => $ours['counter_seconds'] === null ? null : round($ours['counter_seconds'] / 60, 2),
            'provider_minutes' => $providerMinutes,
            'provider_calls'   => $statement['provider_calls'] === null || $statement['provider_calls'] === '' ? null : (int) $statement['provider_calls'],
            'diff_pct'         => $diffPct,
            'status'           => $status,
        ]);
    }

    /** [from, to) as pkg_cdr.starttime strings in the CDR timezone. */
    public function cdrBounds($startDate, $endDate, $timezone)
    {
        $local = new DateTimeZone($timezone ?: 'Africa/Lagos');
        $cdr   = new DateTimeZone($this->cdrTimezone);
        $from  = (new DateTimeImmutable($startDate . ' 00:00:00', $local))->setTimezone($cdr);
        $to    = (new DateTimeImmutable($endDate . ' 00:00:00', $local))->modify('+1 day')->setTimezone($cdr);
        return [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    }

    /** Billed seconds of a calendar-month minutes counter matching the period exactly, else null. */
    private function monthCounter($idTrunk, $startDate, $endDate, $timezone)
    {
        $first = new DateTimeImmutable($startDate);
        if ($first->format('d') !== '01' || $first->modify('last day of this month')->format('Y-m-d') !== $endDate) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM pkg_trunk_cap_policy WHERE id_trunk = ? AND max_minutes IS NOT NULL
               AND period_type = 'month' AND anchor = 'calendar' AND timezone = ? ORDER BY active DESC, id LIMIT 1"
        );
        $stmt->execute([(int) $idTrunk, $timezone]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (! $policy) {
            return null;
        }
        $windowStart = TrunkCapWindow::period($policy, (new DateTimeImmutable($startDate . ' 12:00:00', new DateTimeZone($timezone)))->getTimestamp())['start'];
        $stmt        = $this->db->prepare('SELECT billed_seconds FROM pkg_trunk_cap_usage WHERE id_policy = ? AND window_start = ?');
        $stmt->execute([(int) $policy['id'], TrunkCapWindow::toDb($windowStart)]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    /** A policy's rounding override for this trunk, if any active policy sets one. */
    private function policyRounding($idTrunk)
    {
        $stmt = $this->db->prepare(
            'SELECT rounding_init_sec init, rounding_inc_sec inc FROM pkg_trunk_cap_policy
             WHERE id_trunk = ? AND active = 1 AND rounding_inc_sec IS NOT NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute([(int) $idTrunk]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['init' => (int) $row['init'], 'inc' => (int) $row['inc']] : null;
    }
}
