<?php
/**
 * Trunk cap live usage: current window of every active policy. Read-only.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapUsageController extends Controller
{
    public function init()
    {
        $this->instanceModel = new TrunkCapUsage;
        $this->abstractModel = TrunkCapUsage::model();
        $this->titleReport   = Yii::t('zii', 'Trunk Cap Usage');

        parent::init();
    }

    public function actionRead($asJson = true, $condition = null)
    {
        $this->checkActionAccess([], $this->instanceModel->getModule(), 'canRead');

        Yii::app()->db->setActive(true);
        $service = new TrunkCapService(Yii::app()->db->getPdoInstance());
        $rows    = $service->status(isset($_GET['id_trunk']) ? (int) $_GET['id_trunk'] : null);

        echo json_encode([
            $this->nameRoot  => $rows,
            $this->nameCount => count($rows),
            $this->nameSum   => [],
        ]);
    }

    public function actionSave()
    {
        $this->sendError('Usage is read-only. Use Reset on the Trunk Caps screen.');
    }

    public function actionDestroy()
    {
        $this->sendError('Usage is read-only. Use Reset on the Trunk Caps screen.');
    }
}
