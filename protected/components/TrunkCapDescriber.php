<?php
/**
 * Trunk cap engine: plain-language description of a policy, e.g.
 * "Max 50,000 minutes per calendar month (resets on the 1st, Africa/Lagos time)."
 *
 * Pure PHP (no Yii), shared by the panel grid, the form preview and alerts.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapDescriber
{
    private static $dayNames = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
    private static $isoDays  = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public static function describe(array $p)
    {
        $get = function ($key) use ($p) {
            return isset($p[$key]) && $p[$key] !== '' ? $p[$key] : null;
        };

        $periodLimits = [];
        if ($get('max_minutes') !== null) {
            $periodLimits[] = self::count($get('max_minutes'), 'minute');
        }
        if ($get('max_calls') !== null) {
            $periodLimits[] = self::count($get('max_calls'), $get('count_failed_attempts') ? 'call attempt' : 'call');
        }
        $otherLimits = [];
        if ($get('max_calls_per_hour') !== null) {
            $otherLimits[] = self::count($get('max_calls_per_hour'), 'call') . ' per rolling hour';
        }
        if ($get('max_concurrent') !== null) {
            $otherLimits[] = self::count($get('max_concurrent'), 'concurrent call');
        }
        if ($get('max_cps') !== null) {
            $otherLimits[] = self::count($get('max_cps'), 'call') . ' per second';
        }
        if (! $periodLimits && ! $otherLimits) {
            return 'No limits set.';
        }

        $tz        = $get('timezone') ?: 'Africa/Lagos';
        $sentences = [];
        if ($periodLimits) {
            list($period, $detail) = self::period($p);
            $detail      = trim($detail . ($get('anchor') === 'rolling' || $get('period_type') === 'custom' ? '' : ($detail ? ', ' : '') . $tz . ' time'), ', ');
            $sentences[] = 'Max ' . self::join($periodLimits) . ' ' . $period . ($detail ? ' (' . $detail . ')' : '') . '.';
            if ($otherLimits) {
                $sentences[] = 'Also max ' . self::join($otherLimits) . '.';
            }
        } else {
            $sentences[] = 'Max ' . self::join($otherLimits) . '.';
        }

        $window = self::window($get('window_start'), $get('window_end'), $get('weekdays'));
        if ($window) {
            $sentences[] = 'Applies only to calls starting ' . $window . ', ' . $tz . ' time.';
        }

        $margin = $get('safety_margin_pct');
        if ($margin !== null && (int) $margin < 100) {
            $sentences[] = 'Period limits enforced at ' . (int) $margin . '%.';
        }

        $sentences[] = $get('on_limit') === 'reject'
            ? 'When reached, the call is rejected.'
            : 'When reached, calls fail over to the next trunk.';

        if ($get('max_minutes') !== null && $get('reserve_mode') === 'full') {
            $sentences[] = 'Each call reserves its full maximum duration.';
        }
        if ($get('strict')) {
            $sentences[] = 'Strict: the trunk is blocked if the cap check fails.';
        }
        if ($get('override_until') !== null) {
            $sentences[] = 'Paused until ' . $get('override_until') . ' UTC.';
        }

        return implode(' ', $sentences);
    }

    /** @return array [period phrase, detail for the parenthesis] */
    private static function period(array $p)
    {
        $type   = isset($p['period_type']) ? $p['period_type'] : 'month';
        $anchor = isset($p['anchor']) ? $p['anchor'] : 'calendar';
        $reset  = isset($p['reset_day']) ? (int) $p['reset_day'] : 0;

        if ($anchor === 'rolling') {
            $spans = ['hour' => 'hour', 'day' => '24 hours', 'week' => '7 days', 'month' => '30 days'];
            $span  = $type === 'custom' ? self::duration(isset($p['period_length_sec']) ? (int) $p['period_length_sec'] : 0) : $spans[$type];
            return ['in any rolling ' . $span, ''];
        }
        if ($type === 'custom') {
            return ['per fixed ' . self::duration(isset($p['period_length_sec']) ? (int) $p['period_length_sec'] : 0) . ' block', 'aligned to UTC'];
        }
        if ($anchor === 'billing_day' && $type === 'month') {
            return ['per billing month', 'resets on the ' . self::ordinal($reset) . ($reset > 28 ? ' or the last day of shorter months' : '')];
        }
        if ($anchor === 'billing_day' && $type === 'week') {
            return ['per week', 'resets every ' . (isset(self::$isoDays[$reset]) ? self::$isoDays[$reset] : '?')];
        }

        switch ($type) {
            case 'hour':
                return ['per clock hour', ''];
            case 'day':
                return ['per day', 'resets at midnight'];
            case 'week':
                return ['per week', 'resets on Monday'];
            default:
                return ['per calendar month', 'resets on the 1st'];
        }
    }

    private static function window($start, $end, $weekdays)
    {
        $parts = [];
        if ($start !== null && $end !== null) {
            $parts[] = 'between ' . substr($start, 0, 5) . ' and ' . substr($end, 0, 5);
        }
        if ($weekdays !== null) {
            $days = is_array($weekdays) ? $weekdays : explode(',', $weekdays);
            $days = array_map(function ($d) {
                $d = trim($d);
                return isset(self::$dayNames[$d]) ? self::$dayNames[$d] : $d;
            }, $days);
            $parts[] = 'on ' . self::join($days);
        }
        return implode(' ', $parts);
    }

    private static function count($n, $noun)
    {
        return number_format((int) $n) . ' ' . $noun . ((int) $n === 1 ? '' : 's');
    }

    private static function duration($seconds)
    {
        if ($seconds > 0 && $seconds % 3600 === 0) {
            return self::count($seconds / 3600, 'hour');
        }
        return self::count((int) ceil($seconds / 60), 'minute');
    }

    private static function ordinal($n)
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    }

    private static function join(array $items)
    {
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
