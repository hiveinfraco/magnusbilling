<?php
/**
 * Trunk cap engine: included by CalcAgi::sendCall() after each trunk attempt,
 * once Dial has returned and $this->dialstatus / $this->real_answeredtime are set.
 *
 * Commits the reservation made by BeforesendCalltoTrunk.php. Site-specific
 * post-dial logic goes in AftersendCalltoTrunk.local.php.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
if (! empty($trunkCapReservation)) {
    $trunkCapInit = $trunkCapInc = null;
    if ($this->real_answeredtime > 0 && strlen((string) $MAGNUS->prefixclause)) {
        // Provider buy-rate rounding for the matched prefix, as CalcAgi uses for buycost.
        try {
            $trunkCapRate = $agi->query(
                'SELECT buyrateinitblock, buyrateincrement FROM pkg_rate_provider t JOIN pkg_prefix p ON t.id_prefix = p.id
                 WHERE id_provider = ' . (int) $modelTrunk->id_provider . ' AND ' . $MAGNUS->prefixclause . '
                 ORDER BY LENGTH(prefix) DESC LIMIT 1'
            );
            $trunkCapRate = $trunkCapRate ? $trunkCapRate->fetch(PDO::FETCH_OBJ) : false;
            if ($trunkCapRate) {
                $trunkCapInit = (int) $trunkCapRate->buyrateinitblock;
                $trunkCapInc  = (int) $trunkCapRate->buyrateincrement;
            }
        } catch (Throwable $e) {
            $agi->verbose('[TrunkCaps] Provider rounding lookup failed, counting raw seconds: ' . $e->getMessage(), 1);
        }
    }
    $trunkCapService->commit($trunkCapReservation, $this->dialstatus, $this->real_answeredtime, $trunkCapInit, $trunkCapInc);
    $trunkCapReservation = null;
}

if (file_exists(dirname(__FILE__) . '/AftersendCalltoTrunk.local.php')) {
    include dirname(__FILE__) . '/AftersendCalltoTrunk.local.php';
}
