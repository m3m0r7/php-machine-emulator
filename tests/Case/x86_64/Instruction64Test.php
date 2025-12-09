<?php

declare(strict_types=1);

namespace Tests\Case\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\Intel\x86_64\RexPrefix;
use PHPMachineEmulator\Instruction\Intel\x86_64\Push64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Pop64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Mov64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Movsxd;
use PHPMachineEmulator\Instruction\Intel\x86_64\Arithmetic64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeCPUContext;
use PHPMachineEmulator\Stream\MemoryStream;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

class Instruction64Test extends TestCase
{
    private x86_64 $instructionList;
    private TestRuntime $runtime;

    protected function setUp(): void
    {
        $this->instructionList = new x86_64();
        $this->runtime = new TestRuntime();
        $this->instructionList->setRuntime($this->runtime);

        // Enable 64-bit mode
        $this->runtime->context()->cpu()->setLongMode(true);
        $this->runtime->context()->cpu()->setCompatibilityMode(false);
        $this->runtime->context()->cpu()->setDefaultOperandSize(32);
        $this->runtime->context()->cpu()->setDefaultAddressSize(64);
    }

    public function testRexPrefixOpcodes(): void
    {
        $rexPrefix = new RexPrefix($this->instructionList);
        $opcodes = $rexPrefix->opcodes();

        $this->assertCount(16, $opcodes);
        $this->assertContains(0x40, $opcodes);
        $this->assertContains(0x4F, $opcodes);
    }

    public function testPush64Opcodes(): void
    {
        $push64 = new Push64($this->instructionList);
        $opcodes = $push64->opcodes();

        $this->assertCount(8, $opcodes);
        $this->assertEquals(range(0x50, 0x57), $opcodes);
    }

    public function testPop64Opcodes(): void
    {
        $pop64 = new Pop64($this->instructionList);
        $opcodes = $pop64->opcodes();

        $this->assertCount(8, $opcodes);
        $this->assertEquals(range(0x58, 0x5F), $opcodes);
    }

    public function testMov64Opcodes(): void
    {
        $mov64 = new Mov64($this->instructionList);
        $opcodes = $mov64->opcodes();

        $this->assertContains(0x89, $opcodes);
        $this->assertContains(0x8B, $opcodes);
        $this->assertContains(0xB8, $opcodes);
        $this->assertContains(0xC7, $opcodes);
    }

    public function testMovsxdOpcodes(): void
    {
        $movsxd = new Movsxd($this->instructionList);
        $opcodes = $movsxd->opcodes();

        $this->assertCount(1, $opcodes);
        $this->assertContains(0x63, $opcodes);
    }

    public function testArithmetic64Opcodes(): void
    {
        $arith64 = new Arithmetic64($this->instructionList);
        $opcodes = $arith64->opcodes();

        // ADD opcodes
        $this->assertContains(0x01, $opcodes);
        $this->assertContains(0x03, $opcodes);
        $this->assertContains(0x05, $opcodes);

        // SUB opcodes
        $this->assertContains(0x29, $opcodes);
        $this->assertContains(0x2B, $opcodes);
        $this->assertContains(0x2D, $opcodes);

        // CMP opcodes
        $this->assertContains(0x39, $opcodes);
        $this->assertContains(0x3B, $opcodes);
        $this->assertContains(0x3D, $opcodes);

        // AND opcodes
        $this->assertContains(0x21, $opcodes);
        $this->assertContains(0x23, $opcodes);
        $this->assertContains(0x25, $opcodes);

        // OR opcodes
        $this->assertContains(0x09, $opcodes);
        $this->assertContains(0x0B, $opcodes);
        $this->assertContains(0x0D, $opcodes);

        // XOR opcodes
        $this->assertContains(0x31, $opcodes);
        $this->assertContains(0x33, $opcodes);
        $this->assertContains(0x35, $opcodes);
    }

    public function testInstructionList64Registration(): void
    {
        // REX prefix opcodes should be registered (0x40-0x4F)
        for ($opcode = 0x40; $opcode <= 0x4F; $opcode++) {
            $instruction = $this->instructionList->findInstruction($opcode);
            $this->assertInstanceOf(RexPrefix::class, $instruction, "REX prefix 0x" . dechex($opcode) . " should be registered");
        }

        // MOVSXD should be registered
        $instruction = $this->instructionList->findInstruction(0x63);
        $this->assertInstanceOf(Movsxd::class, $instruction);

        // Push64 should be registered (0x50-0x57)
        for ($opcode = 0x50; $opcode <= 0x57; $opcode++) {
            $instruction = $this->instructionList->findInstruction($opcode);
            $this->assertInstanceOf(Push64::class, $instruction, "PUSH 0x" . dechex($opcode) . " should be registered");
        }

        // Pop64 should be registered (0x58-0x5F)
        for ($opcode = 0x58; $opcode <= 0x5F; $opcode++) {
            $instruction = $this->instructionList->findInstruction($opcode);
            $this->assertInstanceOf(Pop64::class, $instruction, "POP 0x" . dechex($opcode) . " should be registered");
        }
    }

    public function testFindInstruction64BitMode(): void
    {
        // REX prefix should return RexPrefix in 64-bit mode
        $instruction = $this->instructionList->findInstruction(0x48);
        $this->assertInstanceOf(RexPrefix::class, $instruction);

        // MOVSXD
        $instruction = $this->instructionList->findInstruction(0x63);
        $this->assertInstanceOf(Movsxd::class, $instruction);

        // PUSH r64
        $instruction = $this->instructionList->findInstruction(0x50);
        $this->assertInstanceOf(Push64::class, $instruction);

        // POP r64
        $instruction = $this->instructionList->findInstruction(0x58);
        $this->assertInstanceOf(Pop64::class, $instruction);
    }

    public function testRegisterTypesFor64BitRegisters(): void
    {
        // Verify that extended registers R8-R15 are defined
        $this->assertInstanceOf(RegisterType::class, RegisterType::R8);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R9);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R10);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R11);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R12);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R13);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R14);
        $this->assertInstanceOf(RegisterType::class, RegisterType::R15);
    }

    public function testX86DelegationFor32BitInstructions(): void
    {
        // Non-64-bit specific opcodes should delegate to x86
        // NOP (0x90)
        $instruction = $this->instructionList->findInstruction(0x90);
        $this->assertNotNull($instruction);

        // INT (0xCD)
        $instruction = $this->instructionList->findInstruction(0xCD);
        $this->assertNotNull($instruction);

        // RET (0xC3)
        $instruction = $this->instructionList->findInstruction(0xC3);
        $this->assertNotNull($instruction);
    }

    public function testRexPrefixBits(): void
    {
        $cpu = $this->runtime->context()->cpu();

        // Test REX.W (bit 3)
        $cpu->setRex(0x08);
        $this->assertTrue($cpu->rexW());
        $this->assertFalse($cpu->rexR());
        $this->assertFalse($cpu->rexX());
        $this->assertFalse($cpu->rexB());

        // Test REX.R (bit 2)
        $cpu->setRex(0x04);
        $this->assertFalse($cpu->rexW());
        $this->assertTrue($cpu->rexR());
        $this->assertFalse($cpu->rexX());
        $this->assertFalse($cpu->rexB());

        // Test REX.X (bit 1)
        $cpu->setRex(0x02);
        $this->assertFalse($cpu->rexW());
        $this->assertFalse($cpu->rexR());
        $this->assertTrue($cpu->rexX());
        $this->assertFalse($cpu->rexB());

        // Test REX.B (bit 0)
        $cpu->setRex(0x01);
        $this->assertFalse($cpu->rexW());
        $this->assertFalse($cpu->rexR());
        $this->assertFalse($cpu->rexX());
        $this->assertTrue($cpu->rexB());

        // Test all bits
        $cpu->setRex(0x0F);
        $this->assertTrue($cpu->rexW());
        $this->assertTrue($cpu->rexR());
        $this->assertTrue($cpu->rexX());
        $this->assertTrue($cpu->rexB());

        // Test clearRex
        $cpu->clearRex();
        $this->assertFalse($cpu->hasRex());
    }

    public function testLongModeDetection(): void
    {
        $cpu = $this->runtime->context()->cpu();

        // Initially in 64-bit mode (set in setUp)
        $this->assertTrue($cpu->isLongMode());
        $this->assertFalse($cpu->isCompatibilityMode());

        // Switch to compatibility mode
        $cpu->setCompatibilityMode(true);
        $this->assertTrue($cpu->isLongMode());
        $this->assertTrue($cpu->isCompatibilityMode());

        // Disable long mode
        $cpu->setLongMode(false);
        $this->assertFalse($cpu->isLongMode());
    }
}
