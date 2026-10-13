<?php
/**
 * Model for the table "pkg_trunk_cap_usage" (trunk cap engine).
 * Read-only from the panel; counters are written by TrunkCapService.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapUsage extends Model
{
    protected $_module = 'trunkcapusage';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_trunk_cap_usage';
    }

    public function primaryKey()
    {
        return ['id_policy', 'window_start'];
    }

    public function rules()
    {
        return $this->getExtraField([]);
    }

    public function relations()
    {
        return [
            'idPolicy' => [self::BELONGS_TO, 'TrunkCapPolicy', 'id_policy'],
        ];
    }
}
