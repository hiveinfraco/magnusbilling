<?php
/**
 * Trunk cap engine: alert formatting and Telegram delivery.
 *
 * Pure PHP (no Yii), so it is unit-testable. E-mail delivery stays in the
 * Yii commands because it uses the panel's SMTP settings.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class TrunkCapNotifier
{
    const TELEGRAM_API     = 'https://api.telegram.org';
    const TELEGRAM_MAX_LEN = 4000; // Telegram's limit is 4096; leave room for the truncation note

    /**
     * Warning digest from pkg_trunk_cap_event rows (with trunkcode joined).
     *
     * @return array subject, html (e-mail), telegram (Telegram HTML subset)
     */
    public static function warningDigest(array $events)
    {
        $lines = [];
        foreach ($events as $e) {
            $w       = json_decode((string) $e['snapshot'], true) ?: [];
            $lines[] = [
                'time'   => $e['created_at'] . ' UTC',
                'trunk'  => (string) $e['trunkcode'],
                'policy' => isset($w['policy']) ? (string) $w['policy'] : '',
                'usage'  => (isset($w['pct']) ? $w['pct'] : '?') . '% (threshold ' . (isset($w['threshold']) ? $w['threshold'] : '?') . '%)',
            ];
        }

        $subject = count($lines) === 1
            ? 'Trunk cap warning: ' . $lines[0]['trunk']
            : count($lines) . ' trunk cap warnings';

        $rows = '';
        $tg   = [];
        foreach ($lines as $l) {
            $rows .= '<tr><td>' . self::h($l['time']) . '</td><td>' . self::h($l['trunk']) . '</td><td>'
                . self::h($l['policy']) . '</td><td>' . self::h($l['usage']) . '</td></tr>';
            $tg[] = '• <b>' . self::h($l['trunk']) . '</b>, ' . self::h($l['policy']) . ': ' . self::h($l['usage']);
        }
        $html = '<p>These trunks have crossed a cap warning threshold. Percentages are of the provider cap.</p>'
            . '<table border="1" cellpadding="4" cellspacing="0"><tr><th>Time</th><th>Trunk</th><th>Policy</th><th>Usage</th></tr>'
            . $rows . '</table>';

        return [
            'subject'  => $subject,
            'html'     => $html,
            'telegram' => self::truncate('⚠️ <b>' . self::h($subject) . '</b>' . "\n" . implode("\n", $tg)),
        ];
    }

    /**
     * Reconciliation report from TrunkCapReconciler::monthly().
     *
     * @return array subject, html, telegram
     */
    public static function reconciliationReport($month, array $rows, $tolerancePct)
    {
        $mismatches = array_filter($rows, function ($r) {
            return $r['status'] === 'mismatch';
        });
        $missing = count(array_filter($rows, function ($r) {
            return $r['status'] === 'no_statement';
        }));
        if ($mismatches) {
            $summary = count($mismatches) . ' mismatch' . (count($mismatches) === 1 ? '' : 'es');
        } elseif ($missing === count($rows)) {
            $summary = 'no provider statements entered';
        } else {
            $summary = 'all within ' . $tolerancePct . '%';
        }
        if ($missing && $missing < count($rows)) {
            $summary .= ', ' . $missing . ' trunk' . ($missing === 1 ? '' : 's') . ' without a statement';
        }
        $subject = 'Trunk caps reconciliation ' . $month . ': ' . $summary;

        $html = '<p>Our minutes are CDR answered time rounded with each provider\'s billing increments, compared with the statements '
            . 'entered under Routes → Trunk Cap Reconciliation. Tolerance: ' . self::h($tolerancePct) . '%.</p>'
            . '<table border="1" cellpadding="4" cellspacing="0"><tr><th>Trunk</th><th>Period</th><th>Our minutes</th>'
            . '<th>Provider minutes</th><th>Difference</th><th>Our calls</th><th>Provider calls</th><th>Cap counter minutes</th><th>Status</th></tr>';
        $tg = [];
        foreach ($rows as $r) {
            $diff = $r['diff_pct'] === null ? '' : sprintf('%+.2f%%', $r['diff_pct']);
            $html .= '<tr><td>' . self::h($r['trunkcode']) . '</td><td>' . self::h($r['period_start'] . ' → ' . $r['period_end']) . '</td><td>'
                . self::h(self::num($r['our_minutes'])) . '</td><td>' . self::h(self::num($r['provider_minutes'])) . '</td><td>'
                . self::h($diff) . '</td><td>' . self::h(self::num($r['our_calls'], 0)) . '</td><td>' . self::h(self::num($r['provider_calls'], 0))
                . '</td><td>' . self::h(self::num($r['counter_minutes'])) . '</td><td>' . self::h(self::statusLabel($r['status'])) . '</td></tr>';
            $tg[] = ($r['status'] === 'mismatch' ? '❌' : ($r['status'] === 'ok' ? '✅' : '•')) . ' <b>' . self::h($r['trunkcode']) . '</b>: '
                . self::h(self::num($r['our_minutes'])) . ' min'
                . ($r['provider_minutes'] !== null ? ' vs provider ' . self::h(self::num($r['provider_minutes'])) . ' (' . self::h($diff) . ')' : ' (no statement)');
        }
        $html .= '</table>';

        return [
            'subject'  => $subject,
            'html'     => $html,
            'telegram' => self::truncate('📊 <b>' . self::h($subject) . '</b>' . "\n" . implode("\n", $tg)),
        ];
    }

    /**
     * Send a message with the Telegram Bot API (parse_mode HTML).
     *
     * @return array [bool ok, string error]
     */
    public static function sendTelegram($token, $chatId, $text, $apiBase = self::TELEGRAM_API, $timeout = 10)
    {
        if ($token === '' || $chatId === '') {
            return [false, 'Telegram is not configured'];
        }
        $ch = curl_init(rtrim($apiBase, '/') . '/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => 'true',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [false, 'Telegram request failed: ' . $error];
        }
        $json = json_decode($body, true);
        if ($status !== 200 || empty($json['ok'])) {
            // Never echo the token: the API error text does not contain it, the URL does.
            return [false, 'Telegram API error ' . $status . ': ' . (isset($json['description']) ? $json['description'] : substr($body, 0, 200))];
        }
        return [true, ''];
    }

    private static function statusLabel($status)
    {
        $labels = ['ok' => 'OK', 'mismatch' => 'MISMATCH', 'no_statement' => 'No statement'];
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    private static function num($value, $decimals = 1)
    {
        return $value === null ? '' : number_format((float) $value, $decimals);
    }

    private static function truncate($text)
    {
        if (strlen($text) <= self::TELEGRAM_MAX_LEN) {
            return $text;
        }
        // Cut on a line boundary so no HTML tag is left open.
        $cut = substr($text, 0, self::TELEGRAM_MAX_LEN);
        $cut = substr($cut, 0, (int) strrpos($cut, "\n"));
        return $cut . "\n… (truncated, see the e-mail or the panel)";
    }

    private static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
