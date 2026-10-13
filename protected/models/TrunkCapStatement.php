<?php
/**
 * Model for the table "pkg_trunk_cap_statement" (provider statements for reconciliation).
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapStatement extends Model
{
    protected $_module = 'trunkcapstatement';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_trunk_cap_statement';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_trunk, period_start, period_end, timezone, provider_minutes', 'required'],
            ['id_trunk, provider_calls, id_user', 'numerical', 'integerOnly' => true, 'min' => 0],
            ['provider_minutes', 'numerical', 'min' => 0],
            ['period_start, period_end', 'date', 'format' => 'yyyy-MM-dd'],
            ['note', 'length', 'max' => 255],
            ['timezone', 'checkTimezone'],
            ['period_end', 'checkPeriod'],
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

    public function checkPeriod($attribute, $params)
    {
        if ($this->period_start && $this->period_end && $this->period_end < $this->period_start) {
            $this->addError($attribute, Yii::t('zii', 'The period end must be on or after the start.'));
        }
    }
}
