<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../components/TrunkCapService.php';

class TrunkCapServiceMathTest extends TestCase
{
    /** @dataProvider roundingCases */
    public function testBilledSecondsMirrorsCalcAgiBuyRateRounding($seconds, $init, $inc, $expected)
    {
        $this->assertSame($expected, TrunkCapService::billedSeconds($seconds, $init, $inc));
    }

    public function roundingCases()
    {
        return [
            'unanswered'                  => [0, 60, 60, 0],
            'no rounding configured'      => [125, null, null, 125],
            '1/1'                         => [125, 1, 1, 125],
            '60/60 rounds up'             => [125, 60, 60, 180],
            '60/60 exact minute'          => [120, 60, 60, 120],
            '60/1 short call hits minimum' => [7, 60, 1, 60],
            '60/1 after minimum'          => [61, 60, 1, 61],
            '30/6'                        => [31, 30, 6, 36],
            'at init exactly'             => [30, 30, 6, 30],
        ];
    }

    public function testEffectiveCountAppliesMarginButNeverZero()
    {
        $this->assertSame(95, TrunkCapService::effectiveCount(100, 95));
        $this->assertSame(9, TrunkCapService::effectiveCount(10, 95));
        $this->assertSame(1, TrunkCapService::effectiveCount(1, 95));
        $this->assertSame(10, TrunkCapService::effectiveCount(10, 100));
    }
}
