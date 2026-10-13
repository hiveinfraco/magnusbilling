<?php
/**
 * Trunk cap engine: pure period / time-window arithmetic.
 *
 * No Yii and no database: loaded by the AGI by absolute path and by the panel
 * through Yii autoload. All inputs and outputs are UTC epoch seconds; the
 * policy timezone is used only to find local boundaries (midnight, Monday,
 * 1st of month, reset day, peak window).
 *
 * A policy is an array (or pkg_trunk_cap_policy row) with the keys
 * period_type, period_length_sec, anchor, reset_day, timezone,
 * window_start, window_end, weekdays.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapWindow
{
    /** ASSUMPTION: a rolling "month" is 30 days. */
    const ROLLING_MONTH_SECONDS = 2592000;

    private static $dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    /**
     * The counting period that contains $ts.
     *
     * Fixed anchors (calendar, billing_day) return the period boundaries;
     * usage is stored in one pkg_trunk_cap_usage row keyed by 'start'.
     *
     * Rolling anchors return the span of 1-minute buckets to sum:
     * pkg_trunk_cap_minute rows with start <= minute_start < end. The current
     * minute is included, so the window covers ceil(length / 60) buckets.
     *
     * @return array ['start' => int, 'end' => int, 'rolling' => bool]
     */
    public static function period(array $p, $ts)
    {
        $ts = (int) $ts;

        if ($p['anchor'] === 'rolling') {
            $length = (int) ceil(self::rollingLength($p) / 60) * 60;
            $end    = self::minuteStart($ts) + 60;
            return ['start' => $end - $length, 'end' => $end, 'rolling' => true];
        }

        if ($p['period_type'] === 'custom') {
            // Calendar custom periods are aligned to the Unix epoch (UTC).
            $length = self::customLength($p);
            $start  = $ts - self::mod($ts, $length);
            return ['start' => $start, 'end' => $start + $length, 'rolling' => false];
        }

        if ($p['period_type'] === 'hour') {
            // Offset arithmetic instead of setTime() so repeated DST hours stay distinct.
            $offset = self::local($p, $ts)->getOffset();
            $start  = $ts - self::mod($ts + $offset, 3600);
            return ['start' => $start, 'end' => $start + 3600, 'rolling' => false];
        }

        $local = self::local($p, $ts);

        if ($p['anchor'] === 'billing_day') {
            list($start, $end) = $p['period_type'] === 'month'
                ? self::billingMonth($local, (int) $p['reset_day'])
                : self::billingWeek($local, (int) $p['reset_day']);
        } else {
            switch ($p['period_type']) {
                case 'day':
                    $start = $local->setTime(0, 0, 0);
                    $end   = $start->modify('+1 day')->setTime(0, 0, 0);
                    break;
                case 'week':
                    $start = $local->modify('-' . ((int) $local->format('N') - 1) . ' days')->setTime(0, 0, 0);
                    $end   = $start->modify('+7 days')->setTime(0, 0, 0);
                    break;
                case 'month':
                    $start = $local->setDate((int) $local->format('Y'), (int) $local->format('n'), 1)->setTime(0, 0, 0);
                    $end   = $start->modify('first day of next month')->setTime(0, 0, 0);
                    break;
                default:
                    throw new InvalidArgumentException('Unknown period_type: ' . $p['period_type']);
            }
        }

        return ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'rolling' => false];
    }

    /**
     * Whether $ts falls inside the policy's time-of-day / weekday window.
     * Policies without a window always apply.
     *
     * Overnight windows (e.g. 22:00-06:00) belong to the weekday they open
     * on: with weekdays = 'fri', Friday 23:00 and Saturday 03:00 both apply.
     */
    public static function appliesAt(array $p, $ts)
    {
        $local = self::local($p, $ts);
        $days  = self::weekdays($p);
        $today = self::$dayNames[(int) $local->format('N')];

        $start = self::timeToSeconds(isset($p['window_start']) ? $p['window_start'] : null);
        $end   = self::timeToSeconds(isset($p['window_end']) ? $p['window_end'] : null);

        if ($start === null || $end === null) {
            return $days === null || in_array($today, $days, true);
        }

        $now = (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');

        if ($start < $end) {
            $openedOn = ($now >= $start && $now < $end) ? $today : null;
        } elseif ($now >= $start) {
            $openedOn = $today;
        } elseif ($now < $end) {
            $openedOn = self::$dayNames[(int) $local->modify('-1 day')->format('N')];
        } else {
            $openedOn = null;
        }

        return $openedOn !== null && ($days === null || in_array($openedOn, $days, true));
    }

    public static function rollingLength(array $p)
    {
        switch ($p['period_type']) {
            case 'hour':
                return 3600;
            case 'day':
                return 86400;
            case 'week':
                return 604800;
            case 'month':
                return self::ROLLING_MONTH_SECONDS;
            case 'custom':
                return self::customLength($p);
        }
        throw new InvalidArgumentException('Unknown period_type: ' . $p['period_type']);
    }

    public static function minuteStart($ts)
    {
        return (int) $ts - self::mod((int) $ts, 60);
    }

    /** UTC DATETIME string for pkg_trunk_cap_* columns. */
    public static function toDb($ts)
    {
        return gmdate('Y-m-d H:i:s', (int) $ts);
    }

    public static function fromDb($datetime)
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Month period starting on $resetDay at local midnight. Days past the end
     * of a short month clamp to its last day (reset 31 -> Feb 28/29, Apr 30).
     */
    private static function billingMonth(DateTimeImmutable $local, $resetDay)
    {
        $year  = (int) $local->format('Y');
        $month = (int) $local->format('n');

        $start = self::resetInMonth($local, $year, $month, $resetDay);
        if ($start > $local) {
            $prev  = $local->setDate($year, $month, 1)->modify('-1 month');
            $start = self::resetInMonth($local, (int) $prev->format('Y'), (int) $prev->format('n'), $resetDay);
        }

        $next = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)->modify('+1 month');
        $end  = self::resetInMonth($local, (int) $next->format('Y'), (int) $next->format('n'), $resetDay);

        return [$start, $end];
    }

    private static function resetInMonth(DateTimeImmutable $ref, $year, $month, $resetDay)
    {
        $first = $ref->setDate($year, $month, 1);
        $day   = min($resetDay, (int) $first->format('t'));
        return $first->setDate($year, $month, $day)->setTime(0, 0, 0);
    }

    /** Week period starting on ISO weekday $resetDay (1 = Monday) at local midnight. */
    private static function billingWeek(DateTimeImmutable $local, $resetDay)
    {
        $back  = self::mod((int) $local->format('N') - $resetDay, 7);
        $start = $local->modify('-' . $back . ' days')->setTime(0, 0, 0);
        return [$start, $start->modify('+7 days')->setTime(0, 0, 0)];
    }

    private static function local(array $p, $ts)
    {
        $tz = isset($p['timezone']) && $p['timezone'] !== '' ? $p['timezone'] : 'Africa/Lagos';
        return (new DateTimeImmutable('@' . (int) $ts))->setTimezone(new DateTimeZone($tz));
    }

    private static function weekdays(array $p)
    {
        if (! isset($p['weekdays']) || $p['weekdays'] === '' || $p['weekdays'] === []) {
            return null;
        }
        $days = is_array($p['weekdays']) ? $p['weekdays'] : explode(',', $p['weekdays']);
        return array_map('trim', $days);
    }

    private static function timeToSeconds($time)
    {
        if ($time === null || $time === '') {
            return null;
        }
        $parts = array_map('intval', explode(':', $time));
        return $parts[0] * 3600 + (isset($parts[1]) ? $parts[1] : 0) * 60 + (isset($parts[2]) ? $parts[2] : 0);
    }

    private static function customLength(array $p)
    {
        $length = isset($p['period_length_sec']) ? (int) $p['period_length_sec'] : 0;
        if ($length < 60) {
            throw new InvalidArgumentException('Custom periods need period_length_sec >= 60.');
        }
        return $length;
    }

    private static function mod($a, $b)
    {
        return (($a % $b) + $b) % $b;
    }
}
