<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapNotifier.php';

/**
 * Formatting tests, plus Telegram delivery against a local mock Bot API
 * (php -S on 127.0.0.1); no request ever goes to api.telegram.org.
 */
class TrunkCapNotifierTest extends TestCase
{
    private static $server;
    private static $port;
    private static $log;

    public static function setUpBeforeClass(): void
    {
        self::$log  = tempnam(sys_get_temp_dir(), 'tg');
        $router     = tempnam(sys_get_temp_dir(), 'tgrouter') . '.php';
        file_put_contents($router, '<?php
            file_put_contents(' . var_export(self::$log, true) . ', json_encode(["uri" => $_SERVER["REQUEST_URI"], "post" => $_POST]) . "\n", FILE_APPEND);
            header("Content-Type: application/json");
            if (strpos($_SERVER["REQUEST_URI"], "/botBADTOKEN/") === 0) {
                http_response_code(401);
                echo json_encode(["ok" => false, "error_code" => 401, "description" => "Unauthorized"]);
                return;
            }
            echo json_encode(["ok" => true, "result" => ["message_id" => 1]]);');
        self::$port   = 20000 + mt_rand(0, 9999);
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', self::$port); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        @unlink(self::$log);
    }

    private function events()
    {
        return [
            ['created_at' => '2026-10-13 10:00:00', 'trunkcode' => 'carrier<a>',
                'snapshot' => json_encode(['policy' => 'Monthly & peak', 'pct' => 81.5, 'threshold' => 80])],
            ['created_at' => '2026-10-13 10:05:00', 'trunkcode' => 'carrier-b',
                'snapshot' => json_encode(['policy' => 'Daily', 'pct' => 90, 'threshold' => 90])],
        ];
    }

    public function testWarningDigestEscapesHtmlForBothChannels()
    {
        $d = TrunkCapNotifier::warningDigest($this->events());
        $this->assertSame('2 trunk cap warnings', $d['subject']);
        $this->assertStringContainsString('<td>carrier&lt;a&gt;</td><td>Monthly &amp; peak</td><td>81.5% (threshold 80%)</td>', $d['html']);
        $this->assertStringContainsString('• <b>carrier&lt;a&gt;</b>, Monthly &amp; peak: 81.5% (threshold 80%)', $d['telegram']);
        $this->assertSame('Trunk cap warning: carrier-b', TrunkCapNotifier::warningDigest([$this->events()[1]])['subject']);
    }

    public function testReconciliationReportMarksMismatches()
    {
        $rows = [
            ['trunkcode' => 'a', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'our_minutes' => 1000.0, 'provider_minutes' => 1010.0,
                'diff_pct' => -0.99, 'our_calls' => 50, 'provider_calls' => 50, 'counter_minutes' => 1000.0, 'status' => 'ok'],
            ['trunkcode' => 'b', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'our_minutes' => 500.0, 'provider_minutes' => 600.0,
                'diff_pct' => -16.67, 'our_calls' => 9, 'provider_calls' => null, 'counter_minutes' => null, 'status' => 'mismatch'],
            ['trunkcode' => 'c', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'our_minutes' => 12.0, 'provider_minutes' => null,
                'diff_pct' => null, 'our_calls' => 3, 'provider_calls' => null, 'counter_minutes' => null, 'status' => 'no_statement'],
        ];
        $r = TrunkCapNotifier::reconciliationReport('2026-09', $rows, 2);
        $this->assertSame('Trunk caps reconciliation 2026-09: 1 mismatch, 1 trunk without a statement', $r['subject']);
        $this->assertSame('Trunk caps reconciliation 2026-09: no provider statements entered',
            TrunkCapNotifier::reconciliationReport('2026-09', [$rows[2]], 2)['subject']);
        $this->assertStringContainsString('<td>-16.67%</td>', $r['html']);
        $this->assertStringContainsString('<td>MISMATCH</td>', $r['html']);
        $this->assertStringContainsString('❌ <b>b</b>: 500.0 min vs provider 600.0 (-16.67%)', $r['telegram']);
        $this->assertStringContainsString('• <b>c</b>: 12.0 min (no statement)', $r['telegram']);
        $this->assertSame('Trunk caps reconciliation 2026-09: all within 2%', TrunkCapNotifier::reconciliationReport('2026-09', [$rows[0]], 2)['subject']);
    }

    public function testLongTelegramMessagesAreCutOnALineBoundary()
    {
        $events = [];
        for ($i = 0; $i < 200; $i++) {
            $events[] = ['created_at' => 'x', 'trunkcode' => 'trunk-' . $i, 'snapshot' => json_encode(['policy' => str_repeat('p', 30), 'pct' => 80, 'threshold' => 80])];
        }
        $text = TrunkCapNotifier::warningDigest($events)['telegram'];
        $this->assertLessThanOrEqual(4096, strlen($text));
        $this->assertStringEndsWith('… (truncated, see the e-mail or the panel)', $text);
        $this->assertSame(substr_count($text, '<b>'), substr_count($text, '</b>'));
    }

    public function testSendTelegramPostsToTheBotApi()
    {
        file_put_contents(self::$log, '');
        list($ok, $error) = TrunkCapNotifier::sendTelegram('123:ABC', '-100777', '<b>hi</b>', 'http://127.0.0.1:' . self::$port);
        $this->assertSame([true, ''], [$ok, $error]);
        $req = json_decode(trim(file_get_contents(self::$log)), true);
        $this->assertSame('/bot123:ABC/sendMessage', $req['uri']);
        $this->assertSame(['chat_id' => '-100777', 'text' => '<b>hi</b>', 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'], $req['post']);
    }

    public function testSendTelegramReportsApiErrorsWithoutLeakingTheToken()
    {
        list($ok, $error) = TrunkCapNotifier::sendTelegram('BADTOKEN', '1', 'x', 'http://127.0.0.1:' . self::$port);
        $this->assertFalse($ok);
        $this->assertSame('Telegram API error 401: Unauthorized', $error);
        $this->assertStringNotContainsString('BADTOKEN', $error);

        list($ok, $error) = TrunkCapNotifier::sendTelegram('t', '1', 'x', 'http://127.0.0.1:1', 2);
        $this->assertFalse($ok);
        $this->assertStringStartsWith('Telegram request failed', $error);
        $this->assertSame([false, 'Telegram is not configured'], TrunkCapNotifier::sendTelegram('', '1', 'x'));
    }
}
