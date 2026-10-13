<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../resources/asterisk/Magnus.php';
require_once __DIR__ . '/../../../resources/asterisk/CalcAgi.php';
require_once __DIR__ . '/../../components/TrunkCapService.php';

/**
 * Drives the real CalcAgi::sendCall() (trunk-group query, failover loop and
 * the Before/AftersendCalltoTrunk.php hooks) against a migrated dev database.
 * Only Asterisk is faked: Dial outcomes are scripted per trunk.
 * Skipped unless TRUNKCAP_TEST_DSN is set (see TrunkCapServiceTest).
 */
class TrunkCapAgiHookTest extends TestCase
{
    /** @var TrunkCapFakeAgi */
    private $agi;
    /** @var TrunkCapFakeMagnus */
    private $magnus;
    private $trunks = [];
    private $group;

    protected function setUp(): void
    {
        if (! getenv('TRUNKCAP_TEST_DSN')) {
            $this->markTestSkipped('TRUNKCAP_TEST_DSN not set');
        }
        $this->agi = new TrunkCapFakeAgi(getenv('TRUNKCAP_TEST_DSN'), getenv('TRUNKCAP_TEST_USER') ?: 'root', getenv('TRUNKCAP_TEST_PASS') ?: '');
        $this->agi->exec("INSERT INTO pkg_provider (provider_name) SELECT 'tctest-provider'
            WHERE NOT EXISTS (SELECT 1 FROM pkg_provider WHERE provider_name = 'tctest-provider')");
        $this->group = $this->insert('pkg_trunk_group', ['name' => 'tctest-group-' . bin2hex(random_bytes(4)), 'type' => 1]);
        $this->magnus = new TrunkCapFakeMagnus($this->agi);
    }

    protected function tearDown(): void
    {
        if (! $this->agi) {
            return;
        }
        foreach ($this->trunks as $id) {
            $this->agi->exec("DELETE FROM pkg_trunk_cap_reservation WHERE id_trunk = $id");
            $this->agi->exec("DELETE FROM pkg_trunk_cap_event WHERE id_trunk = $id");
            $this->agi->exec("DELETE FROM pkg_trunk WHERE id = $id");
        }
        $this->agi->exec('DELETE FROM pkg_trunk_group WHERE id = ' . (int) $this->group);
    }

    private function insert($table, array $row)
    {
        $stmt = $this->agi->prepare("INSERT INTO $table (" . implode(',', array_keys($row)) . ') VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')');
        $stmt->execute(array_values($row));
        return (int) $this->agi->lastInsertId();
    }

    /** Trunk in the test group, in order. */
    private function trunk($code, array $policy = null)
    {
        $provider = $this->agi->query("SELECT id FROM pkg_provider WHERE provider_name = 'tctest-provider'")->fetchColumn();
        $id = $this->insert('pkg_trunk', [
            'id_provider' => $provider, 'trunkcode' => 'tctest-' . $code . '-' . bin2hex(random_bytes(3)),
            'host' => '192.0.2.1', 'fromdomain' => '192.0.2.1', 'providertech' => 'pjsip', 'providerip' => '192.0.2.1',
            'user' => '', 'secret' => '', 'allow' => 'ulaw', 'link_sms' => '', 'sms_res' => '', 'language' => 'en',
        ]);
        $this->trunks[] = $id;
        $this->insert('pkg_trunk_group_trunk', ['id_trunk_group' => $this->group, 'id_trunk' => $id]);
        if ($policy) {
            $this->insert('pkg_trunk_cap_policy', array_merge([
                'id_trunk' => $id, 'name' => $code, 'period_type' => 'month', 'anchor' => 'calendar', 'timezone' => 'Africa/Lagos',
            ], $policy));
        }
        return $id;
    }

    private function call(array $outcomes)
    {
        $this->magnus->outcomes = $outcomes;
        $this->magnus->dials    = [];
        $this->magnus->uniqueid = 'tctest-' . microtime(true);
        $calc = new CalcAgi();
        $calc->init();
        $calc->tariffObj = [[
            'trunk_group_type' => 1, 'id_trunk_group' => $this->group, 'trunk_group_name' => 'tctest',
            'timeout' => 600, 'id_prefix' => 1, 'rc_directmedia' => 'no',
        ]];
        $calc->sendCall($this->agi, '2348012345678', $this->magnus);
        return $calc;
    }

    private function dialedTrunks()
    {
        return array_map(function ($d) {
            return $d['trunk'];
        }, $this->magnus->dials);
    }

    private function trunkCode($id)
    {
        return $this->agi->query("SELECT trunkcode FROM pkg_trunk WHERE id = $id")->fetchColumn();
    }

    private function usageRow($idTrunk)
    {
        $row = $this->agi->query("SELECT u.calls, u.attempts, u.reserved_calls, u.billed_seconds, u.reserved_seconds
            FROM pkg_trunk_cap_usage u JOIN pkg_trunk_cap_policy p ON p.id = u.id_policy WHERE p.id_trunk = $idTrunk")
            ->fetch(PDO::FETCH_ASSOC);
        return $row ? array_map('intval', $row) : null;
    }

    // ---- tests ---------------------------------------------------------------

    public function testAnsweredCallIsReservedThenCommittedWithRealDuration()
    {
        $a = $this->trunk('a', ['max_calls' => 5]);
        $this->call(['ANSWER:125']);
        $this->assertSame([$this->trunkCode($a)], $this->dialedTrunks());
        $this->assertSame(['calls' => 1, 'attempts' => 1, 'reserved_calls' => 0, 'billed_seconds' => 125, 'reserved_seconds' => 0],
            $this->usageRow($a));
        $this->assertSame('0', (string) $this->agi->query("SELECT COUNT(*) FROM pkg_trunk_cap_reservation
            WHERE id_trunk = $a AND released_at IS NULL")->fetchColumn());
    }

    public function testCappedTrunkFailsOverToNextTrunkInGroup()
    {
        $a = $this->trunk('a', ['max_calls' => 1]);
        $b = $this->trunk('b');
        $this->call(['ANSWER:10']);
        $this->call(['ANSWER:10']);
        $this->assertSame([$this->trunkCode($b)], $this->dialedTrunks());
        $this->assertContains('TRUNK_CAP_BLOCKED', $this->agi->eventCodes());
    }

    public function testChanUnavailAttemptIsReleasedAndFailsOver()
    {
        $a = $this->trunk('a', ['max_concurrent' => 1]);
        $b = $this->trunk('b');
        $calc = $this->call(['CHANUNAVAIL', 'ANSWER:30']);
        $this->assertSame([$this->trunkCode($a), $this->trunkCode($b)], $this->dialedTrunks());
        $this->assertSame(['calls' => 0, 'attempts' => 1, 'reserved_calls' => 0, 'billed_seconds' => 0, 'reserved_seconds' => 0],
            $this->usageRow($a));
        $this->assertSame('ANSWER', $calc->dialstatus);
    }

    public function testAllTrunksCappedSendsNothingAndFlagsTheChannel()
    {
        $this->trunk('a', ['max_calls' => 1]);
        $this->trunk('b', ['max_calls' => 1]);
        $this->call(['ANSWER:5']);
        $this->call(['ANSWER:5']);
        $this->agi->variables = [];
        $calc = $this->call([]);
        $this->assertSame([], $this->dialedTrunks());
        $this->assertSame('BLOCKED', $this->agi->variables['TRUNKCAP_RESULT'] ?? null);
        $this->assertSame('34', $this->agi->variables['HANGUPCAUSE'] ?? null, 'caller must get a prompt 503, not silence');
        $this->assertContains('TRUNK_CAP_ALL_BLOCKED', $this->agi->eventCodes());
        $this->assertSame('', $calc->dialstatus);
    }

    public function testRejectPolicyStopsFailover()
    {
        $this->trunk('a', ['max_calls' => 1, 'on_limit' => 'reject']);
        $this->trunk('b');
        $this->call(['ANSWER:5']);
        $this->call(['ANSWER:5']);
        $this->assertSame([], $this->dialedTrunks());
        $this->assertSame('BLOCKED', $this->agi->variables['TRUNKCAP_RESULT'] ?? null);
    }

    public function testDialTimeLimitIsLoweredNearTheMinutesCap()
    {
        $a = $this->trunk('a', ['max_minutes' => 15]); // 900 s
        $this->call(['ANSWER:600']);
        $this->call(['ANSWER:300']);
        $this->assertSame([300], array_column($this->magnus->dials, 'timeout'));
        $this->assertContains('TRUNK_CAP_LIMITED', $this->agi->eventCodes());
        $this->assertSame(900, $this->usageRow($a)['billed_seconds']);
    }

    public function testDebugModeChecksWithoutReserving()
    {
        $a = $this->trunk('a', ['max_calls' => 1]);
        $this->agi->debugMode = true;
        $this->call(['ANSWER:5']);
        $this->assertSame([$this->trunkCode($a)], $this->dialedTrunks());
        $this->assertNull($this->usageRow($a));
    }
}

/** AGI stand-in: real PDO to the test DB, Asterisk commands recorded. */
class TrunkCapFakeAgi extends PDO
{
    public $debugMode = false;
    public $variables = [];
    public $events    = [];
    public $request   = ['agi_uniqueid' => 'tctest'];

    public function __construct($dsn, $user, $pass)
    {
        parent::__construct($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function verbose($message, $level = 1)
    {
        return ['code' => 200, 'result' => 1, 'data' => ''];
    }

    public function verboseEvent($component, $code, $message, $level = 4, array $context = [])
    {
        $this->events[] = $code;
    }

    public function eventCodes()
    {
        return $this->events;
    }

    public function set_variable($name, $value)
    {
        $this->variables[$name] = $value;
    }

    public function get_variable($name, $getvalue = false)
    {
        $value = isset($this->variables[$name]) ? $this->variables[$name] : '';
        return $getvalue ? $value : ['result' => 1, 'data' => $value];
    }

    public function execute($command, $args = '')
    {
    }

    public function stream_file($file, $escape = '')
    {
    }
}

/** Magnus with Dial replaced by scripted outcomes ("ANSWER:<seconds>", "BUSY", "CHANUNAVAIL", ...). */
class TrunkCapFakeMagnus extends Magnus
{
    public $outcomes = [];
    public $dials    = [];
    private $fakeAgi;

    public function __construct($agi)
    {
        $this->fakeAgi       = $agi;
        $this->CallerID      = '15550001';
        $this->agiconfig     = ['dialcommand_param' => ',60,L(%timeout%:61000:30000)'];
        $this->config        = ['global' => ['max_call_duration' => 7200]];
        $this->play_audio    = 0;
        $this->prefixclause  = '';
        $this->modelSip      = (object) ['directmedia' => 'no', 'ringfalse' => '0', 'addparameter' => '', 'cnl' => ''];
    }

    public function run_dial($agi, $dialstr, $dialparams = '', $trunk_directmedia = 'no', $timeout = 3600, $max_long = 2147483647)
    {
        preg_match('#@(.+)$#', $dialstr, $m);
        $this->dials[] = ['trunk' => $m[1], 'timeout' => (int) $timeout];
        $outcome = (string) array_shift($this->outcomes);
        list($status, $seconds) = array_pad(explode(':', $outcome), 2, 0);
        $agi->variables['DIALSTATUS']    = $status;
        $agi->variables['ANSWEREDTIME']  = (int) $seconds;
    }

    public function startRecordCall(&$agi, $addFileNameUser = '', $isDid = false)
    {
    }

    public function stopRecordCall(&$agi)
    {
    }

    public function hangup(&$agi, $code = '')
    {
    }
}
