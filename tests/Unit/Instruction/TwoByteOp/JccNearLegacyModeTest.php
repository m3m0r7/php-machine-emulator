<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\JccNear;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

final class JccNearLegacyModeTest extends TestCase
{
    public function testJneNearInRealModeUsesCsRelativeOffset(): void
    {
        // Need enough room to place the code stream at a linear address > 0xFFFF.
        $runtime = new TestRuntime(memorySize: 0x40000);
        $runtime->setRealMode16();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $jccNear = new JccNear($instructionList);

        // CS=0x2000 (base 0x20000). This is the scenario that regressed MikeOS:
        // the old JccNear implementation masked the linear address to 16-bit and dropped the CS base.
        $runtime->setRegister(RegisterType::CS, 0x2000, 16);

        // JNE (0x0F 0x85): taken when ZF=0.
        $runtime->memoryAccessor()->setZeroFlag(false);

        // Simulate that the decoder already consumed opcode bytes (0x0F 0x85) at 0x2015E,
        // so the stream offset is at the start of the displacement.
        $opcodeStart = 0x2015E;
        $operandStart = $opcodeStart + 2;
        $runtime->memory()->setOffset($operandStart);

        // rel16 = +0x0093, so target = next(0x20162) + 0x93 = 0x201F5.
        $runtime->memory()->write(chr(0x93) . chr(0x00));
        $runtime->memory()->setOffset($operandStart);

        $opcodeKey = (0x0F << 8) | 0x85;
        $jccNear->process($runtime, [$opcodeKey]);

        $this->assertSame(0x201F5, $runtime->memory()->offset());
    }
}
