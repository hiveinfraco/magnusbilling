<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapPanel.php';

class TrunkCapPanelTest extends TestCase
{
    public function testBlockedEventIsReadable()
    {
        $rows = TrunkCapPanel::diagnosticDetails('TRUNK_CAP_BLOCKED', [
            'trunk' => 'carrier-a', 'policy' => 4, 'limit' => 'max_minutes', 'action' => 'skip_trunk',
        ]);
        $this->assertSame([
            ['label' => 'Trunk', 'value' => 'carrier-a'],
            ['label' => 'Limit reached', 'value' => 'Minutes per period'],
            ['label' => 'Cap policy', 'value' => 'ID 4'],
            ['label' => 'Action', 'value' => 'Fail over to the next trunk'],
        ], $rows);
    }

    public function testStrictFailureLimitedAndAllBlocked()
    {
        $strict = TrunkCapPanel::diagnosticDetails('TRUNK_CAP_BLOCKED', ['trunk' => 'x', 'policy' => null, 'limit' => null, 'action' => 'skip_trunk']);
        $this->assertSame('Cap check failed (strict policy)', $strict[2]['value']);

        $limited = TrunkCapPanel::diagnosticDetails('TRUNK_CAP_LIMITED', ['trunk' => 'x', 'timeout' => 280, 'was' => 3600]);
        $this->assertSame('280 s (was 3600 s)', $limited[1]['value']);

        $all = TrunkCapPanel::diagnosticDetails('TRUNK_CAP_ALL_BLOCKED', ['trunkGroup' => 3]);
        $this->assertSame('ID 3', $all[0]['value']);
        $this->assertSame([], TrunkCapPanel::diagnosticDetails('TRUNK_CAP_UNKNOWN', []));
    }

    public function testLoaderScriptPointsAtTheBundledFileWithCacheBuster()
    {
        $this->assertMatchesRegularExpression(
            '#^\(function\(\)\{var s=document\.createElement\("script"\);s\.src="resources\\\\/trunkcaps\\\\/trunkcaps\.js\?v=\d+";#',
            TrunkCapPanel::loaderScript()
        );
    }
}
