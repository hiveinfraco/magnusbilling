<?php
/**
 * Trunk cap engine: per-minute maintenance (installed in /etc/cron.d by TrunkCapSchema).
 *
 *   php cron.php TrunkCapSweep
 *
 * 1. Releases reservations whose AGI died (crash, kill, server restart),
 *    using pkg_cdr for the answered time when the call was recorded.
 * 2. Purges released reservations and old rolling-window buckets.
 * 3. Sends a digest of new warning events by e-mail (trunkcaps_alert_email,
 *    admin SMTP) and Telegram (trunkcaps_telegram_*), each channel tracked
 *    separately in pkg_trunk_cap_event.notified_at / telegram_at.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapSweepCommand extends CConsoleCommand
{
    /** Warnings older than this are marked as handled without e-mail, to avoid a backlog flood. */
    const NOTIFY_MAX_AGE_SECONDS = 86400;

    public function run($args)
    {
        Yii::app()->db->setActive(true);
        $service = new TrunkCapService(Yii::app()->db->getPdoInstance(), function ($message, $error) {
            fwrite($error ? STDERR : STDOUT, '[TrunkCapSweep] ' . $message . PHP_EOL);
        });

        $result = $service->sweep();
        if (array_sum($result) > 0) {
            echo '[TrunkCapSweep] ' . json_encode($result) . PHP_EOL;
        }

        $this->notifyWarnings();
        return 0;
    }

    private function notifyWarnings()
    {
        $alerts = new TrunkCapAlerts();
        $this->deliver('notified_at', $alerts->emailConfigured(), function (array $digest) use ($alerts) {
            return $alerts->email($digest['subject'], $digest['html']);
        });
        $this->deliver('telegram_at', $alerts->telegramConfigured(), function (array $digest) use ($alerts) {
            return $alerts->telegram($digest['telegram']);
        });
    }

    /**
     * Send pending warnings on one channel. Each channel has its own column, so
     * a failure on one never re-sends on the other. Unconfigured channels and
     * warnings older than NOTIFY_MAX_AGE_SECONDS are marked handled without sending.
     */
    private function deliver($column, $configured, callable $send)
    {
        $db = Yii::app()->db;
        $db->createCommand(
            "UPDATE pkg_trunk_cap_event SET $column = UTC_TIMESTAMP()
             WHERE event = 'warned' AND $column IS NULL" . ($configured ? ' AND created_at < :cutoff' : '')
        )->execute($configured ? [':cutoff' => TrunkCapWindow::toDb(time() - self::NOTIFY_MAX_AGE_SECONDS)] : []);
        if (! $configured) {
            return;
        }

        $events = $db->createCommand(
            "SELECT e.id, e.created_at, e.snapshot, t.trunkcode
             FROM pkg_trunk_cap_event e LEFT JOIN pkg_trunk t ON t.id = e.id_trunk
             WHERE e.event = 'warned' AND e.$column IS NULL ORDER BY e.id LIMIT 50"
        )->queryAll();
        if ($events && $send(TrunkCapNotifier::warningDigest($events))) {
            $ids = implode(',', array_map('intval', array_column($events, 'id')));
            $db->createCommand("UPDATE pkg_trunk_cap_event SET $column = UTC_TIMESTAMP() WHERE id IN ($ids)")->execute();
        }
    }
}
