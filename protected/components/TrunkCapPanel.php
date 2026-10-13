<?php
/**
 * Trunk cap engine: loads the panel modules into the prebuilt Ext JS app.
 *
 * MagnusBilling ships a compiled app.js per theme and a build-generated
 * index.html, so the Trunk Caps screens are a separate script. index.php
 * (SiteController::actionIndex) is included by every index.html variant,
 * so the loader is emitted from there.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapPanel
{
    const SCRIPT = 'resources/trunkcaps/trunkcaps.js';

    public static function loaderScript()
    {
        $file = dirname(__FILE__) . '/../../' . self::SCRIPT;
        if (! file_exists($file)) {
            return '';
        }
        $src = self::SCRIPT . '?v=' . filemtime($file);
        return '(function(){var s=document.createElement("script");s.src=' . json_encode($src)
            . ';document.head.appendChild(s);})();' . PHP_EOL;
    }

    /**
     * Call Diagnostics rows for the TRUNK_CAP_* events emitted by
     * resources/asterisk/BeforesendCalltoTrunk.php.
     */
    public static function diagnosticDetails($code, array $context)
    {
        $get = function ($key) use ($context) {
            return isset($context[$key]) ? (string) $context[$key] : '';
        };
        $limits = [
            'max_calls'          => 'Calls per period',
            'max_calls_per_hour' => 'Calls per rolling hour',
            'max_minutes'        => 'Minutes per period',
            'max_concurrent'     => 'Concurrent calls',
            'max_cps'            => 'Calls per second',
        ];
        $actions = ['skip_trunk' => 'Fail over to the next trunk', 'reject' => 'Reject the call'];

        switch ($code) {
            case 'TRUNK_CAP_BLOCKED':
                return [
                    ['label' => self::t('Trunk'), 'value' => $get('trunk')],
                    ['label' => self::t('Limit reached'), 'value' => isset($limits[$get('limit')]) ? self::t($limits[$get('limit')]) : $get('limit')],
                    ['label' => self::t('Cap policy'), 'value' => $get('policy') !== '' ? 'ID ' . $get('policy') : self::t('Cap check failed (strict policy)')],
                    ['label' => self::t('Action'), 'value' => isset($actions[$get('action')]) ? self::t($actions[$get('action')]) : $get('action')],
                ];
            case 'TRUNK_CAP_LIMITED':
                return [
                    ['label' => self::t('Trunk'), 'value' => $get('trunk')],
                    ['label' => self::t('Maximum call duration'), 'value' => $get('timeout') . ' s (' . self::t('was') . ' ' . $get('was') . ' s)'],
                ];
            case 'TRUNK_CAP_ALL_BLOCKED':
                return [
                    ['label' => self::t('Trunk group'), 'value' => $get('trunkGroup') !== '' ? 'ID ' . $get('trunkGroup') : ''],
                    ['label' => self::t('Result'), 'value' => self::t('No trunk dialed: every remaining trunk is at its cap')],
                ];
        }
        return [];
    }

    private static function t($message)
    {
        return class_exists('Yii', false) ? Yii::t('zii', $message) : $message;
    }
}
