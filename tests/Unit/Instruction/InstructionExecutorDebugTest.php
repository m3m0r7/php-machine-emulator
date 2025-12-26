<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutorDebug;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Debug\DebugContext;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

final class InstructionExecutorDebugTest extends TestCase
{
    public function testRecordExecutionStopsAfterInstructionLimit(): void
    {
        $context = new DebugContext(stopAfterInsns: 2);
        $debug = new InstructionExecutorDebug($context);
        $runtime = new TestRuntime();

        $debug->recordExecution($runtime, 0x100);
        $this->assertSame(1, $debug->instructionCount());

        $this->expectException(HaltException::class);
        $debug->recordExecution($runtime, 0x101);
    }

    public function testStopOnRspBelowThresholdInLongMode(): void
    {
        $context = new DebugContext(stopOnRspBelowThreshold: 0x1000);
        $debug = new InstructionExecutorDebug($context);
        $runtime = new TestRuntime();
        $runtime->cpuContext()->setLongMode(true);
        $runtime->setRegister(RegisterType::ESP, 0x800, 64);

        $this->expectException(HaltException::class);
        $debug->maybeStopOnRspBelow($runtime, 0x200, 0x100);
    }

    public function testTraceExecutionOverrideTakesPrecedence(): void
    {
        $context = new DebugContext(traceExecution: true);
        $debug = new InstructionExecutorDebug($context);
        $runtime = new TestRuntime();

        $this->assertTrue($debug->shouldTraceExecution($runtime));
    }
}
