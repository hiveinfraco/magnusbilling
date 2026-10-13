<?php
/**
 * Trunk cap policies: CRUD, plain-language preview, window reset and pause.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapPolicyController extends Controller
{
    public $attributeOrder = 't.id_trunk ASC, t.id ASC';
    public $extraValues    = ['idTrunk' => 'trunkcode'];
    public $fieldsFkReport = [
        'id_trunk' => [
            'table'       => 'pkg_trunk',
            'pk'          => 'id',
            'fieldReport' => 'trunkcode',
        ],
    ];

    /** Empty form fields that mean "unlimited" / "not set" and must be stored as NULL. */
    private $nullable = [
        'period_length_sec', 'reset_day', 'window_start', 'window_end', 'weekdays', 'max_calls',
        'max_calls_per_hour', 'max_minutes', 'max_concurrent', 'max_cps', 'rounding_init_sec', 'rounding_inc_sec',
    ];

    /** Longest pause an admin can set from the panel. */
    const MAX_OVERRIDE_MINUTES = 10080; // 7 days

    public function init()
    {
        $this->instanceModel = new TrunkCapPolicy;
        $this->abstractModel = TrunkCapPolicy::model();
        $this->titleReport   = Yii::t('zii', 'Trunk Caps');

        parent::init();
    }

    public function beforeSave($values)
    {
        foreach ($this->nullable as $field) {
            if (array_key_exists($field, $values) && ($values[$field] === '' || $values[$field] === [])) {
                $values[$field] = null;
            }
        }
        if (isset($values['weekdays']) && is_array($values['weekdays'])) {
            $values['weekdays'] = implode(',', $values['weekdays']);
        }
        return $values;
    }

    public function setAttributesModels($attributes, $models)
    {
        foreach ($attributes as $key => $row) {
            if (is_array($row) && isset($row['period_type'])) {
                $attributes[$key]['summary'] = TrunkCapDescriber::describe($row);
            }
        }
        return $attributes;
    }

    /** Plain-language description of the values currently in the form. */
    public function actionPreview()
    {
        $this->checkActionAccess([], $this->instanceModel->getModule(), 'canRead');
        $values = $this->beforeSave($this->getAttributesRequest());

        echo json_encode([
            $this->nameSuccess => true,
            'summary'          => TrunkCapDescriber::describe($values),
        ]);
    }

    /** Zero the current window's counters. Body: id. */
    public function actionReset()
    {
        $this->checkActionAccess([], $this->instanceModel->getModule(), 'canUpdate');
        $values = $this->getAttributesRequest();

        try {
            $snapshot = $this->service()->resetWindow((int) $values['id'], (int) Yii::app()->session['id_user']);
        } catch (Exception $e) {
            $this->sendError($e->getMessage());
        }

        echo json_encode([
            $this->nameSuccess => true,
            $this->nameMsg     => Yii::t('zii', 'Counters reset for the window starting {start} UTC.', ['{start}' => $snapshot['window_start']]),
        ]);
    }

    /** Pause a policy. Body: id, minutes (0 ends the pause). */
    public function actionOverride()
    {
        $this->checkActionAccess([], $this->instanceModel->getModule(), 'canUpdate');
        $values  = $this->getAttributesRequest();
        $minutes = isset($values['minutes']) ? (int) $values['minutes'] : -1;
        if ($minutes < 0 || $minutes > self::MAX_OVERRIDE_MINUTES) {
            $this->sendError('Pause must be between 0 and {max} minutes.', ['{max}' => self::MAX_OVERRIDE_MINUTES]);
        }

        try {
            $this->service()->setOverride((int) $values['id'], $minutes === 0 ? null : time() + $minutes * 60,
                (int) Yii::app()->session['id_user']);
        } catch (Exception $e) {
            $this->sendError($e->getMessage());
        }

        echo json_encode([
            $this->nameSuccess => true,
            $this->nameMsg     => $minutes === 0
                ? Yii::t('zii', 'Policy enforced again.')
                : Yii::t('zii', 'Policy paused for {minutes} minutes.', ['{minutes}' => $minutes]),
        ]);
    }

    private function service()
    {
        Yii::app()->db->setActive(true);
        return new TrunkCapService(Yii::app()->db->getPdoInstance());
    }
}
