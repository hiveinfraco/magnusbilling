<?php
/**
 * Model for the table "pkg_trunk_cap_policy" (trunk cap engine).
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapPolicy extends Model
{
    protected $_module = 'trunkcappolicy';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_trunk_cap_policy';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_trunk, name, period_type, anchor, timezone', 'required'],
            ['id_trunk, active, period_length_sec, reset_day, max_calls, max_calls_per_hour, max_minutes,
                max_concurrent, max_cps, count_failed_attempts, safety_margin_pct, rounding_init_sec,
                rounding_inc_sec, strict', 'numerical', 'integerOnly' => true],
            ['max_calls, max_calls_per_hour, max_minutes, max_concurrent, max_cps, rounding_inc_sec',
                'numerical', 'min' => 1, 'allowEmpty' => true],
            ['rounding_init_sec', 'numerical', 'min' => 0, 'allowEmpty' => true],
            ['safety_margin_pct', 'numerical', 'min' => 1, 'max' => 100],
            ['active, count_failed_attempts, strict', 'in', 'range' => [0, 1]],
            ['name', 'length', 'max' => 100],
            ['period_type', 'in', 'range' => ['hour', 'day', 'week', 'month', 'custom']],
            ['anchor', 'in', 'range' => ['calendar', 'billing_day', 'rolling']],
            ['on_limit', 'in', 'range' => ['skip_trunk', 'reject']],
            ['window_start, window_end', 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', 'allowEmpty' => true],
            ['warn_pct', 'match', 'pattern' => '/^(\d{1,3}(,\d{1,3})*)?$/', 'message' => Yii::t('zii', 'Use comma-separated percentages. Ex: 80,90')],
            ['timezone', 'checkTimezone'],
            ['weekdays', 'checkWeekdays'],
            ['period_type', 'checkPeriod'],
            ['window_start', 'checkWindow'],
            ['max_calls', 'checkHasLimit'],
            ['id_trunk', 'exist', 'className' => 'Trunk', 'attributeName' => 'id'],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idTrunk' => [self::BELONGS_TO, 'Trunk', 'id_trunk'],
        ];
    }

    public function checkTimezone($attribute, $params)
    {
        if (! in_array($this->timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->addError($attribute, Yii::t('zii', 'Unknown timezone. Ex: Africa/Lagos'));
        }
    }

    public function checkWeekdays($attribute, $params)
    {
        if ($this->weekdays === null || $this->weekdays === '') {
            $this->weekdays = null;
            return;
        }
        $days = is_array($this->weekdays) ? $this->weekdays : explode(',', $this->weekdays);
        $days = array_map('trim', $days);
        if (array_diff($days, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])) {
            $this->addError($attribute, Yii::t('zii', 'Weekdays must be a list of mon,tue,wed,thu,fri,sat,sun.'));
            return;
        }
        $this->weekdays = implode(',', array_unique($days));
    }

    public function checkPeriod($attribute, $params)
    {
        if ($this->period_type === 'custom') {
            if ((int) $this->period_length_sec < 60) {
                $this->addError('period_length_sec', Yii::t('zii', 'Custom periods need a length of at least 60 seconds.'));
            }
            if ($this->anchor === 'billing_day') {
                $this->addError('anchor', Yii::t('zii', 'Custom periods can be calendar (aligned to the epoch) or rolling, not billing day.'));
            }
        } else {
            $this->period_length_sec = null;
        }

        if ($this->anchor !== 'billing_day') {
            $this->reset_day = null;
            return;
        }

        if ($this->period_type === 'month') {
            $max = 31;
        } elseif ($this->period_type === 'week') {
            $max = 7;
        } else {
            $this->addError('anchor', Yii::t('zii', 'Billing day anchors apply only to weekly and monthly periods.'));
            return;
        }
        if ((int) $this->reset_day < 1 || (int) $this->reset_day > $max) {
            $this->addError('reset_day', Yii::t('zii', 'Reset day must be between 1 and {max}.', ['{max}' => $max]));
        }
    }

    public function checkWindow($attribute, $params)
    {
        $start = $this->window_start === '' ? null : $this->window_start;
        $end   = $this->window_end === '' ? null : $this->window_end;
        if (($start === null) !== ($end === null)) {
            $this->addError($attribute, Yii::t('zii', 'Set both window start and window end, or neither.'));
        } elseif ($start !== null && $start === $end) {
            $this->addError($attribute, Yii::t('zii', 'Window start and end cannot be equal. Leave both empty for all day.'));
        }
        $this->window_start = $start;
        $this->window_end   = $end;
    }

    public function checkHasLimit($attribute, $params)
    {
        foreach (['max_calls', 'max_calls_per_hour', 'max_minutes', 'max_concurrent', 'max_cps'] as $limit) {
            if ($this->$limit !== null && $this->$limit !== '') {
                return;
            }
        }
        $this->addError($attribute, Yii::t('zii', 'Set at least one limit.'));
    }

    /**
     * Warning thresholds as sorted integers, e.g. [80, 90].
     */
    public function getWarnThresholds()
    {
        $values = array_filter(array_map('intval', explode(',', (string) $this->warn_pct)));
        $values = array_unique(array_filter($values, function ($v) {
            return $v > 0 && $v < 100;
        }));
        sort($values);
        return array_values($values);
    }
}
