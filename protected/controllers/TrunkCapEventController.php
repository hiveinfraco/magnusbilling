<?php
/**
 * Trunk cap audit log (blocked, warned, reset, override, swept). Read-only.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapEventController extends Controller
{
    public $attributeOrder = 't.id DESC';
    public $extraValues    = ['idTrunk' => 'trunkcode', 'idPolicy' => 'name'];
    public $fieldsFkReport = [
        'id_trunk'  => ['table' => 'pkg_trunk', 'pk' => 'id', 'fieldReport' => 'trunkcode'],
        'id_policy' => ['table' => 'pkg_trunk_cap_policy', 'pk' => 'id', 'fieldReport' => 'name'],
    ];

    public function init()
    {
        $this->instanceModel = new TrunkCapEvent;
        $this->abstractModel = TrunkCapEvent::model();
        $this->titleReport   = Yii::t('zii', 'Trunk Cap Log');

        parent::init();
    }

    public function actionSave()
    {
        $this->sendError('The trunk cap log is read-only.');
    }

    public function actionDestroy()
    {
        $this->sendError('The trunk cap log is read-only.');
    }
}
