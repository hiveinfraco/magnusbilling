<?php
/**
 * Trunk cap engine: per-minute maintenance (installed in /etc/cron.d by TrunkCapSchema).
 *
 *   php cron.php TrunkCapSweep
 *
 * 1. Releases reservations whose AGI died (crash, kill, server restart),
 *    using pkg_cdr for the answered time when the call was recorded.
 * 2. Purges released reservations and old rolling-window buckets.
 * 3. E-mails a digest of new warning events to trunkcaps_alert_email,
 *    using the admin SMTP settings (same as AlarmCommand).
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
        $db     = Yii::app()->db;
        $cutoff = TrunkCapWindow::toDb(time() - self::NOTIFY_MAX_AGE_SECONDS);

        $db->createCommand(
            "UPDATE pkg_trunk_cap_event SET notified_at = UTC_TIMESTAMP()
             WHERE event = 'warned' AND notified_at IS NULL AND created_at < :cutoff"
        )->execute([':cutoff' => $cutoff]);

        $events = $db->createCommand(
            "SELECT e.id, e.created_at, e.snapshot, t.trunkcode
             FROM pkg_trunk_cap_event e LEFT JOIN pkg_trunk t ON t.id = e.id_trunk
             WHERE e.event = 'warned' AND e.notified_at IS NULL
             ORDER BY e.id LIMIT 50"
        )->queryAll();
        if (! $events) {
            return;
        }

        $to = trim((string) $db->createCommand(
            "SELECT config_value FROM pkg_configuration WHERE config_key = 'trunkcaps_alert_email'"
        )->queryScalar());
        if ($to === '') {
            return; // nothing configured: leave pending, they expire after NOTIFY_MAX_AGE_SECONDS
        }

        $rows = '';
        foreach ($events as $e) {
            $w = json_decode($e['snapshot'], true) ?: [];
            $rows .= '<tr><td>' . CHtml::encode($e['created_at']) . ' UTC</td><td>' . CHtml::encode($e['trunkcode'])
                . '</td><td>' . CHtml::encode(isset($w['policy']) ? $w['policy'] : '') . '</td><td>'
                . CHtml::encode((isset($w['pct']) ? $w['pct'] : '?') . '% (threshold ' . (isset($w['threshold']) ? $w['threshold'] : '?') . '%)')
                . '</td></tr>';
        }
        $subject = count($events) === 1
            ? 'Trunk cap warning: ' . $events[0]['trunkcode']
            : count($events) . ' trunk cap warnings';
        $body = '<p>These trunks have crossed a cap warning threshold. Percentages are of the provider cap.</p>'
            . '<table border="1" cellpadding="4" cellspacing="0"><tr><th>Time</th><th>Trunk</th><th>Policy</th><th>Usage</th></tr>'
            . $rows . '</table>';

        if ($this->sendMail($to, $subject, $body)) {
            $ids = implode(',', array_map('intval', array_column($events, 'id')));
            $db->createCommand("UPDATE pkg_trunk_cap_event SET notified_at = UTC_TIMESTAMP() WHERE id IN ($ids)")->execute();
        }
    }

    private function sendMail($to, $subject, $body)
    {
        $smtp = Smtps::model()->find('id_user = 1');
        if (! isset($smtp->id) || $smtp->host == '' || $smtp->host == 'mail.magnusbilling.com'
            || $smtp->username == '' || $smtp->password == '' || $smtp->port == '') {
            fwrite(STDERR, '[TrunkCapSweep] Warnings pending: admin SMTP is not configured.' . PHP_EOL);
            return false;
        }

        Yii::import('application.extensions.phpmailer.JPhpMailer');
        $mail = new JPhpMailer;
        $mail->IsSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = $smtp->host;
        $mail->SMTPSecure = $smtp->encryption == 'null' ? '' : $smtp->encryption;
        $mail->Username   = $smtp->username;
        $mail->Password   = $smtp->password;
        $mail->Port       = $smtp->port;
        $mail->CharSet    = 'utf-8';
        $mail->SetFrom($smtp->username, 'Trunk Caps');
        $mail->Subject = $subject;
        $mail->AltBody = strip_tags(str_replace('</tr>', "\n", $body));
        $mail->MsgHTML($body);
        foreach (array_filter(array_map('trim', explode(',', $to))) as $address) {
            $mail->AddAddress($address);
        }

        try {
            return (bool) $mail->Send();
        } catch (Exception $e) {
            fwrite(STDERR, '[TrunkCapSweep] Warning e-mail failed: ' . $e->getMessage() . PHP_EOL);
            return false;
        }
    }
}
