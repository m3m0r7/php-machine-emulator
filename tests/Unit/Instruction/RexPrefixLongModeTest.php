<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\x86_64 as X86_64InstructionList;
use PHPMachineEmulator\Instruction\Intel\x86_64\RexPrefix;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

final class RexPrefixLongModeTest extends TestCase
{
    public function testRexPrefixReturnsContinueAndDoesNotExecuteNextOpcode(): void
    {
        $instructionList = new X86_64InstructionList();
        $rexPrefix = new RexPrefix($instructionList);

        $runtime = new class extends TestRuntime {
            public bool $executeCalled = false;

            public function execute(int|array $opcodes): ExecutionStatus
            {
                $this->executeCalled = true;
                throw new \RuntimeException('RexPrefix must not call Runtime::execute()');
            }
        };

        $runtime->cpuContext()->setLongMode(true);
        $runtime->cpuContext()->setCompatibilityMode(false);

        // Set up a REX prefix followed by a two-byte opcode (0F 1E C0).
        $runtime->memory()->setOffset(0);
        $runtime->memory()->write(chr(0x48) . chr(0x0F) . chr(0x1E) . chr(0xC0));

        // Emulate that the prefix byte has already been consumed by the decoder.
        $runtime->memory()->setOffset(1);

        $result = $rexPrefix->process($runtime, [0x48]);

        $this->assertSame(ExecutionStatus::CONTINUE, $result);
        $this->assertSame(1, $runtime->memory()->offset());
        $this->assertTrue($runtime->cpuContext()->rexW());
        $this->assertFalse($runtime->executeCalled);
    }
}

