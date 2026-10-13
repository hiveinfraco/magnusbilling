<?php
/**
 * Provider statements and their reconciliation against our CDR minutes.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapStatementController extends Controller
{
    public $attributeOrder = 't.period_start DESC, t.id_trunk ASC';
    public $extraValues    = ['idTrunk' => 'trunkcode'];
    public $fieldsFkReport = [
        'id_trunk' => ['table' => 'pkg_trunk', 'pk' => 'id', 'fieldReport' => 'trunkcode'],
    ];

    public function init()
    {
        $this->instanceModel = new TrunkCapStatement;
        $this->abstractModel = TrunkCapStatement::model();
        $this->titleReport   = Yii::t('zii', 'Trunk Cap Reconciliation');

        parent::init();
    }

    public function beforeSave($values)
    {
        if (array_key_exists('provider_calls', $values) && $values['provider_calls'] === '') {
            $values['provider_calls'] = null;
        }
        if ($this->isNewRecord) {
            $values['id_user'] = (int) Yii::app()->session['id_user'];
        }
        return $values;
    }

    /** Adds our CDR figures, the difference and the status to each statement row. */
    public function setAttributesModels($attributes, $models)
    {
        if (! $attributes) {
            return $attributes;
        }
        Yii::app()->db->setActive(true);
        $reconciler = new TrunkCapReconciler(Yii::app()->db->getPdoInstance());
        $tolerance  = (float) TrunkCapAlerts::setting('trunkcaps_reconcile_tolerance_pct', '2');

        foreach ($attributes as $key => $row) {
            if (is_array($row) && isset($row['period_start'], $row['id_trunk'])) {
                $attributes[$key] = $reconciler->compare($row, $tolerance);
            }
        }
        return $attributes;
    }
}
