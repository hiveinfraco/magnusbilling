<?php
/**
 * Model for the table "pkg_trunk_cap_event" (trunk cap audit log).
 * Append-only; rows are written by TrunkCapService and panel actions.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapEvent extends Model
{
    protected $_module = 'trunkcapevent';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_trunk_cap_event';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_trunk, event', 'required'],
            ['id_trunk, id_policy, id_user', 'numerical', 'integerOnly' => true],
            ['event', 'in', 'range' => ['warned', 'blocked', 'reset', 'override', 'swept', 'seeded']],
            ['uniqueid', 'length', 'max' => 50],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idTrunk'  => [self::BELONGS_TO, 'Trunk', 'id_trunk'],
            'idPolicy' => [self::BELONGS_TO, 'TrunkCapPolicy', 'id_policy'],
        ];
    }
}
