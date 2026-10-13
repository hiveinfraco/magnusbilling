<?php
/**
 * Trunk cap engine: included by CalcAgi::sendCall() before each trunk attempt.
 *
 * Runs in sendCall()'s scope: $this (CalcAgi), $agi, $MAGNUS, $modelTrunk,
 * $modelTrunks, $key, $trunkcode and $timeout (Dial time limit, seconds).
 * Returning false skips this trunk and fails over to the next one.
 *
 * Site-specific pre-dial logic that used to live in this file goes in
 * BeforesendCalltoTrunk.local.php, which is chained at the end.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
require_once dirname(__FILE__) . '/../../protected/components/TrunkCapService.php';

$trunkCapReservation = null; // committed by AftersendCalltoTrunk.php

// A policy with on_limit = reject already stopped this call on an earlier trunk.
if (! empty($trunkCapRejected)) {
    return false;
}

$trunkCapService = new TrunkCapService($agi, function ($message, $error) use ($agi) {
    $agi->verbose('[TrunkCaps] ' . $message, $error ? 1 : 4);
});

// Call Diagnostics runs in debug mode: check only, never reserve.
$trunkCapDecision = $trunkCapService->reserve($modelTrunk->id, $MAGNUS->uniqueid, $timeout, (bool) $agi->debugMode);

if (! $trunkCapDecision['allow']) {
    $agi->verboseEvent('Trunk', 'TRUNK_CAP_BLOCKED', 'Trunk skipped because a cap limit was reached.', 2, [
        'trunk'      => $trunkcode,
        'trunkGroup' => $this->tariffObj[0]['id_trunk_group'],
        'policy'     => $trunkCapDecision['id_policy'],
        'limit'      => $trunkCapDecision['limit'],
        'action'     => $trunkCapDecision['action'],
        'reason'     => $trunkCapDecision['reason'],
    ]);
    if ($trunkCapDecision['action'] === 'reject') {
        $trunkCapRejected = true;
    }
    if (! empty($trunkCapRejected) || $key === count($modelTrunks) - 1) {
        // No trunk will be dialed for this call because of caps; visible to the dialplan and in the AGI log.
        $agi->set_variable('TRUNKCAP_RESULT', 'BLOCKED');
        $agi->verboseEvent('Trunk', 'TRUNK_CAP_ALL_BLOCKED', 'Call not sent: trunk caps blocked every remaining trunk.', 1, [
            'trunkGroup' => $this->tariffObj[0]['id_trunk_group'],
            'action'     => $trunkCapDecision['action'],
        ]);
    }
    return false;
}

if ($trunkCapDecision['limited']) {
    $agi->verboseEvent('Trunk', 'TRUNK_CAP_LIMITED', 'Call duration limited to the minutes left under the trunk cap.', 3, [
        'trunk'   => $trunkcode,
        'timeout' => $trunkCapDecision['timeout'],
        'was'     => $timeout,
    ]);
    $timeout = $trunkCapDecision['timeout'];
}
$trunkCapReservation = $trunkCapDecision['reservation'];

if (file_exists(dirname(__FILE__) . '/BeforesendCalltoTrunk.local.php')) {
    $trunkCapLocalResult = include dirname(__FILE__) . '/BeforesendCalltoTrunk.local.php';
    if ($trunkCapLocalResult === false) {
        if ($trunkCapReservation) {
            $trunkCapService->commit($trunkCapReservation, 'SKIPPED', 0);
            $trunkCapReservation = null;
        }
        return false;
    }
}

return true;
