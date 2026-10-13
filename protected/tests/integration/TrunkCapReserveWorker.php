<?php
/**
 * Concurrency test worker: waits until a shared start time, calls reserve()
 * once and prints the decision as JSON. Never commits, so allowed calls stay
 * in flight.
 *
 * Usage: php TrunkCapReserveWorker.php <dsn> <user> <pass> <id_trunk> <start_epoch_float> <uniqueid>
 */
require_once __DIR__ . '/../../components/TrunkCapService.php';

list(, $dsn, $user, $pass, $idTrunk, $start, $uniqueid) = $argv;

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
while (microtime(true) < (float) $start) {
    usleep(1000);
}
$service = new TrunkCapService($db, function ($message, $error) {
    fwrite(STDERR, $message . PHP_EOL);
});
echo json_encode($service->reserve((int) $idTrunk, $uniqueid, 600));
