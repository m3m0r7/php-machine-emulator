<?php

declare(strict_types=1);

namespace Tests\Unit\LogicBoard;

use PHPMachineEmulator\LogicBoard\Debug\DebugContext;
use PHPUnit\Framework\TestCase;

final class DebugContextTest extends TestCase
{
    public function testDefaultsAreDisabled(): void
    {
        $context = new DebugContext();

        $this->assertFalse($context->countInstructionsEnabled());
        $this->assertSame(0, $context->ipSampleEvery());
        $this->assertSame(0, $context->stopAfterInsns());
        $this->assertSame(0, $context->stopAfterSecs());
        $this->assertSame(10000, $context->stopAfterTimeEvery());
        $this->assertNull($context->traceExecution());
        $this->assertSame([], $context->traceIpSet());
        $this->assertSame(10, $context->traceIpLimit());
        $this->assertSame([], $context->stopIpSet());
        $this->assertSame([], $context->traceCflowToSet());
        $this->assertSame(10, $context->traceCflowLimit());
        $this->assertSame([], $context->stopCflowToSet());
        $this->assertSame(0, $context->stopOnRspBelowThreshold());
        $this->assertSame(0, $context->stopOnCflowToBelowThreshold());
        $this->assertSame(0, $context->zeroOpcodeLoopLimit());
        $this->assertSame(0, $context->stackPreviewOnIpStopBytes());
        $this->assertSame(0, $context->dumpCodeOnIpStopLength());
        $this->assertSame(0, $context->dumpCodeOnIpStopBefore());
        $this->assertFalse($context->dumpPageFaultContext());
        $this->assertSame(0, $context->dumpCodeOnPfLength());
        $this->assertSame(0, $context->dumpCodeOnPfBefore());
        $this->assertSame(0, $context->pfComparePhysDelta());
    }

    public function testConstructorOverridesValues(): void
    {
        $context = new DebugContext(
            countInstructionsEnabled: true,
            ipSampleEvery: 123,
            stopAfterInsns: 5,
            stopAfterSecs: 9,
            stopAfterTimeEvery: 77,
            traceExecution: true,
            traceIpSet: [0x10 => true],
            traceIpLimit: 3,
            stopIpSet: [0x20 => true],
            traceCflowToSet: [0x30 => true],
            traceCflowLimit: 4,
            stopCflowToSet: [0x40 => true],
            stopOnRspBelowThreshold: 0x1000,
            stopOnCflowToBelowThreshold: 0x2000,
            zeroOpcodeLoopLimit: 255,
            stackPreviewOnIpStopBytes: 64,
            dumpCodeOnIpStopLength: 32,
            dumpCodeOnIpStopBefore: 16,
            dumpPageFaultContext: true,
            dumpCodeOnPfLength: 128,
            dumpCodeOnPfBefore: 8,
            pfComparePhysDelta: 0x200,
        );

        $this->assertTrue($context->countInstructionsEnabled());
        $this->assertSame(123, $context->ipSampleEvery());
        $this->assertSame(5, $context->stopAfterInsns());
        $this->assertSame(9, $context->stopAfterSecs());
        $this->assertSame(77, $context->stopAfterTimeEvery());
        $this->assertTrue($context->traceExecution());
        $this->assertSame([0x10 => true], $context->traceIpSet());
        $this->assertSame(3, $context->traceIpLimit());
        $this->assertSame([0x20 => true], $context->stopIpSet());
        $this->assertSame([0x30 => true], $context->traceCflowToSet());
        $this->assertSame(4, $context->traceCflowLimit());
        $this->assertSame([0x40 => true], $context->stopCflowToSet());
        $this->assertSame(0x1000, $context->stopOnRspBelowThreshold());
        $this->assertSame(0x2000, $context->stopOnCflowToBelowThreshold());
        $this->assertSame(255, $context->zeroOpcodeLoopLimit());
        $this->assertSame(64, $context->stackPreviewOnIpStopBytes());
        $this->assertSame(32, $context->dumpCodeOnIpStopLength());
        $this->assertSame(16, $context->dumpCodeOnIpStopBefore());
        $this->assertTrue($context->dumpPageFaultContext());
        $this->assertSame(128, $context->dumpCodeOnPfLength());
        $this->assertSame(8, $context->dumpCodeOnPfBefore());
        $this->assertSame(0x200, $context->pfComparePhysDelta());
    }
}
