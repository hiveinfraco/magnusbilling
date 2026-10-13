<?php
/**
 * Trunk cap engine: monthly reconciliation against provider statements.
 *
 *   php cron.php TrunkCapReconcile                  previous month, print only
 *   php cron.php TrunkCapReconcile 2026-09          given month, print only
 *   php cron.php TrunkCapReconcile send [2026-09]   also e-mail and Telegram the report
 *
 * Installed in /etc/cron.d/magnusbilling-trunkcaps as "send" on the 2nd of each month.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapReconcileCommand extends CConsoleCommand
{
    public function run($args)
    {
        $send  = in_array('send', $args, true);
        $month = null;
        foreach ($args as $a) {
            if (preg_match('/^\d{4}-\d{2}$/', $a)) {
                $month = $a;
            }
        }
        $month     = $month ?: (new DateTimeImmutable('first day of last month'))->format('Y-m');
        $tolerance = (float) TrunkCapAlerts::setting('trunkcaps_reconcile_tolerance_pct', '2');

        Yii::app()->db->setActive(true);
        $rows = (new TrunkCapReconciler(Yii::app()->db->getPdoInstance()))->monthly($month, $tolerance);

        printf("Trunk caps reconciliation %s (tolerance %s%%)\n", $month, $tolerance);
        printf("%-20s %-23s %12s %12s %9s %8s %8s %8s  %s\n", 'Trunk', 'Period', 'Our min', 'Provider', 'Diff', 'Calls', 'Prov.', 'Counter', 'Status');
        foreach ($rows as $r) {
            printf("%-20s %-23s %12s %12s %9s %8s %8s %8s  %s\n",
                $r['trunkcode'], $r['period_start'] . '..' . $r['period_end'],
                number_format($r['our_minutes'], 1), $r['provider_minutes'] === null ? '-' : number_format($r['provider_minutes'], 1),
                $r['diff_pct'] === null ? '-' : sprintf('%+.2f%%', $r['diff_pct']), $r['our_calls'],
                $r['provider_calls'] === null ? '-' : $r['provider_calls'],
                $r['counter_minutes'] === null ? '-' : number_format($r['counter_minutes'], 1), $r['status']);
        }

        if (! $send || ! $rows) {
            return 0;
        }
        $report = TrunkCapNotifier::reconciliationReport($month, $rows, $tolerance);
        $alerts = new TrunkCapAlerts();
        $sent   = [];
        if ($alerts->emailConfigured() && $alerts->email($report['subject'], $report['html'])) {
            $sent[] = 'e-mail';
        }
        if ($alerts->telegramConfigured() && $alerts->telegram($report['telegram'])) {
            $sent[] = 'Telegram';
        }
        echo $sent ? 'Sent by ' . implode(' and ', $sent) . ".\n" : "Not sent: no alert channel is configured or delivery failed.\n";
        return $sent ? 0 : 1;
    }
}
