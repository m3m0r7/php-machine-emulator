<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Jbe;
use PHPMachineEmulator\Instruction\Intel\x86\Jc;
use PHPMachineEmulator\Instruction\Intel\x86\Jnc;
use PHPMachineEmulator\Instruction\Intel\x86\Jz;
use PHPMachineEmulator\Instruction\Intel\x86\Jnz;
use PHPMachineEmulator\Instruction\Intel\x86\Ja;
use PHPMachineEmulator\Instruction\Intel\x86\Jl;
use PHPMachineEmulator\Instruction\Intel\x86\Jle;
use PHPMachineEmulator\Instruction\Intel\x86\Jg;
use PHPMachineEmulator\Instruction\Intel\x86\Js;
use PHPMachineEmulator\Instruction\Intel\x86\Jns;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for conditional jump instructions (Jcc)
 */
class JccTest extends InstructionTestCase
{
    private Jbe $jbe;
    private Jc $jc;
    private Jnc $jnc;
    private Jz $jz;
    private Jnz $jnz;
    private Ja $ja;
    private Jl $jl;
    private Jle $jle;
    private Jg $jg;
    private Js $js;
    private Jns $jns;

    protected function setUp(): void
    {
        parent::setUp();
        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->jbe = new Jbe($instructionList);
        $this->jc = new Jc($instructionList);
        $this->jnc = new Jnc($instructionList);
        $this->jz = new Jz($instructionList);
        $this->jnz = new Jnz($instructionList);
        $this->ja = new Ja($instructionList);
        $this->jl = new Jl($instructionList);
        $this->jle = new Jle($instructionList);
        $this->jg = new Jg($instructionList);
        $this->js = new Js($instructionList);
        $this->jns = new Jns($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0x76 => $this->jbe,
            0x72 => $this->jc,
            0x73 => $this->jnc,
            0x74 => $this->jz,
            0x75 => $this->jnz,
            0x77 => $this->ja,
            0x7C => $this->jl,
            0x7E => $this->jle,
            0x7F => $this->jg,
            0x78 => $this->js,
            0x79 => $this->jns,
            default => null,
        };
    }

    /**
     * Get the current instruction pointer (offset in memory stream)
     */
    protected function getIP(): int
    {
        return $this->memoryStream->offset();
    }

    // ========================================
    // JC (Jump if Carry) - 0x72 Tests
    // ========================================

    public function testJcTakenWhenCarrySet(): void
    {
        // JC with CF=1 should jump
        $this->setCarryFlag(true);
        // JC +0x10 (opcode=0x72, displacement=0x10)
        // After reading opcode (1) + displacement (1), IP = 2
        // Target = 2 + 0x10 = 0x12
        $this->executeBytes([0x72, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJcNotTakenWhenCarryClear(): void
    {
        // JC with CF=0 should not jump
        $this->setCarryFlag(false);
        $this->executeBytes([0x72, 0x10]);

        // IP should be at position after reading opcode + displacement = 2
        $this->assertSame(2, $this->getIP());
    }

    public function testJcNegativeDisplacement(): void
    {
        // JC with negative displacement (backwards jump)
        // Start at offset 0x20
        $this->memoryStream->setOffset(0x20);
        $this->memoryStream->write(chr(0x72) . chr(0xF0)); // JC -16 (0xF0 = -16 signed)
        $this->memoryStream->setOffset(0x20);

        $this->setCarryFlag(true);
        $opcode = $this->memoryStream->byte();
        $this->jc->process($this->runtime, [$opcode]);

        // IP = 0x22 (after reading) + (-16) = 0x12
        $this->assertSame(0x12, $this->getIP());
    }

    // ========================================
    // JNC (Jump if Not Carry) - 0x73 Tests
    // ========================================

    public function testJncTakenWhenCarryClear(): void
    {
        // JNC with CF=0 should jump
        $this->setCarryFlag(false);
        $this->executeBytes([0x73, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJncNotTakenWhenCarrySet(): void
    {
        // JNC with CF=1 should not jump
        $this->setCarryFlag(true);
        $this->executeBytes([0x73, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JZ (Jump if Zero) - 0x74 Tests
    // ========================================

    public function testJzTakenWhenZeroSet(): void
    {
        // JZ with ZF=1 should jump
        $this->memoryAccessor->setZeroFlag(true);
        $this->executeBytes([0x74, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJzNotTakenWhenZeroClear(): void
    {
        // JZ with ZF=0 should not jump
        $this->memoryAccessor->setZeroFlag(false);
        $this->executeBytes([0x74, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JNZ (Jump if Not Zero) - 0x75 Tests
    // ========================================

    public function testJnzTakenWhenZeroClear(): void
    {
        // JNZ with ZF=0 should jump
        $this->memoryAccessor->setZeroFlag(false);
        $this->executeBytes([0x75, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJnzNotTakenWhenZeroSet(): void
    {
        // JNZ with ZF=1 should not jump
        $this->memoryAccessor->setZeroFlag(true);
        $this->executeBytes([0x75, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JBE (Jump if Below or Equal) - 0x76 Tests
    // CF=1 OR ZF=1
    // ========================================

    public function testJbeTakenWhenCarrySet(): void
    {
        // JBE with CF=1, ZF=0 should jump
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(false);
        $this->executeBytes([0x76, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJbeTakenWhenZeroSet(): void
    {
        // JBE with CF=0, ZF=1 should jump
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);
        $this->executeBytes([0x76, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJbeTakenWhenBothSet(): void
    {
        // JBE with CF=1, ZF=1 should jump
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->executeBytes([0x76, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJbeNotTakenWhenBothClear(): void
    {
        // JBE with CF=0, ZF=0 should not jump
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->executeBytes([0x76, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JA (Jump if Above) - 0x77 Tests
    // CF=0 AND ZF=0
    // ========================================

    public function testJaTakenWhenBothClear(): void
    {
        // JA with CF=0, ZF=0 should jump
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->executeBytes([0x77, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJaNotTakenWhenCarrySet(): void
    {
        // JA with CF=1 should not jump
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(false);
        $this->executeBytes([0x77, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    public function testJaNotTakenWhenZeroSet(): void
    {
        // JA with ZF=1 should not jump
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);
        $this->executeBytes([0x77, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JS (Jump if Sign) - 0x78 Tests
    // SF=1
    // ========================================

    public function testJsTakenWhenSignSet(): void
    {
        // JS with SF=1 should jump
        $this->memoryAccessor->setSignFlag(true);
        $this->executeBytes([0x78, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJsNotTakenWhenSignClear(): void
    {
        // JS with SF=0 should not jump
        $this->memoryAccessor->setSignFlag(false);
        $this->executeBytes([0x78, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JNS (Jump if Not Sign) - 0x79 Tests
    // SF=0
    // ========================================

    public function testJnsTakenWhenSignClear(): void
    {
        // JNS with SF=0 should jump
        $this->memoryAccessor->setSignFlag(false);
        $this->executeBytes([0x79, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJnsNotTakenWhenSignSet(): void
    {
        // JNS with SF=1 should not jump
        $this->memoryAccessor->setSignFlag(true);
        $this->executeBytes([0x79, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JL (Jump if Less - signed) - 0x7C Tests
    // SF != OF
    // ========================================

    public function testJlTakenWhenSfSetOfClear(): void
    {
        // JL with SF=1, OF=0 should jump (SF != OF)
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7C, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJlTakenWhenSfClearOfSet(): void
    {
        // JL with SF=0, OF=1 should jump (SF != OF)
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(true);
        $this->executeBytes([0x7C, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJlNotTakenWhenBothSame(): void
    {
        // JL with SF=0, OF=0 should not jump (SF == OF)
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7C, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JLE (Jump if Less or Equal - signed) - 0x7E Tests
    // ZF=1 OR SF!=OF
    // ========================================

    public function testJleTakenWhenZeroSet(): void
    {
        // JLE with ZF=1 should jump
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7E, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJleTakenWhenLess(): void
    {
        // JLE with SF!=OF should jump
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7E, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJleNotTakenWhenGreater(): void
    {
        // JLE with ZF=0 and SF==OF should not jump
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7E, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // JG (Jump if Greater - signed) - 0x7F Tests
    // ZF=0 AND SF==OF
    // ========================================

    public function testJgTakenWhenGreater(): void
    {
        // JG with ZF=0, SF==OF should jump
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7F, 0x10]);

        $this->assertSame(0x12, $this->getIP());
    }

    public function testJgNotTakenWhenZeroSet(): void
    {
        // JG with ZF=1 should not jump
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7F, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    public function testJgNotTakenWhenLess(): void
    {
        // JG with SF!=OF should not jump
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->executeBytes([0x7F, 0x10]);

        $this->assertSame(2, $this->getIP());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testJumpToSameAddress(): void
    {
        // JC with displacement 0 (infinite loop)
        $this->setCarryFlag(true);
        $this->executeBytes([0x72, 0x00]); // JC +0

        $this->assertSame(2, $this->getIP()); // Position after reading instruction
    }

    public function testMaxPositiveDisplacement(): void
    {
        // JC with max positive displacement (+127)
        $this->setCarryFlag(true);
        $this->executeBytes([0x72, 0x7F]); // JC +127

        // IP = 2 + 127 = 129 = 0x81
        $this->assertSame(0x81, $this->getIP());
    }

    public function testMaxNegativeDisplacement(): void
    {
        // JC with max negative displacement (-128 = 0x80)
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->write(chr(0x72) . chr(0x80)); // JC -128
        $this->memoryStream->setOffset(0x100);

        $this->setCarryFlag(true);
        $opcode = $this->memoryStream->byte();
        $this->jc->process($this->runtime, [$opcode]);

        // IP = 0x102 + (-128) = 0x82
        $this->assertSame(0x82, $this->getIP());
    }
}
