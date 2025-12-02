<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\PushReg;
use PHPMachineEmulator\Instruction\Intel\x86\PopReg;
use PHPMachineEmulator\Instruction\Intel\x86\PushImm;
use PHPMachineEmulator\Instruction\Intel\x86\Pusha;
use PHPMachineEmulator\Instruction\Intel\x86\Popa;
use PHPMachineEmulator\Instruction\Intel\x86\Pushf;
use PHPMachineEmulator\Instruction\Intel\x86\Popf;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for PUSH and POP instructions
 *
 * PUSH reg opcodes: 0x50-0x57 (EAX, ECX, EDX, EBX, ESP, EBP, ESI, EDI)
 * POP reg opcodes: 0x58-0x5F (EAX, ECX, EDX, EBX, ESP, EBP, ESI, EDI)
 * PUSH imm8: 0x6A
 * PUSH imm16/32: 0x68
 * PUSHA: 0x60
 * POPA: 0x61
 */
class PushPopTest extends InstructionTestCase
{
    private PushReg $pushReg;
    private PopReg $popReg;
    private PushImm $pushImm;
    private Pusha $pusha;
    private Popa $popa;
    private Pushf $pushf;
    private Popf $popf;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->pushReg = new PushReg($instructionList);
        $this->popReg = new PopReg($instructionList);
        $this->pushImm = new PushImm($instructionList);
        $this->pusha = new Pusha($instructionList);
        $this->popa = new Popa($instructionList);
        $this->pushf = new Pushf($instructionList);
        $this->popf = new Popf($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match (true) {
            $opcode >= 0x50 && $opcode <= 0x57 => $this->pushReg,
            $opcode >= 0x58 && $opcode <= 0x5F => $this->popReg,
            $opcode === 0x68 || $opcode === 0x6A => $this->pushImm,
            $opcode === 0x60 => $this->pusha,
            $opcode === 0x61 => $this->popa,
            $opcode === 0x9C => $this->pushf,
            $opcode === 0x9D => $this->popf,
            default => null,
        };
    }

    /**
     * Initialize stack pointer to a known location
     */
    private function initStack(int $sp = 0x8000): void
    {
        $this->setRegister(RegisterType::ESP, $sp);
    }

    /**
     * Get current stack pointer
     */
    private function getStackPointer(): int
    {
        return $this->getRegister(RegisterType::ESP);
    }

    // ========================================
    // PUSH reg (0x50-0x57) Tests
    // ========================================

    public function testPushEax(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x12345678);

        $this->executeBytes([0x50]); // PUSH EAX

        // ESP should decrement by 4 (32-bit mode)
        $this->assertSame(0x7FFC, $this->getStackPointer());
        // Value should be on stack
        $this->assertSame(0x12345678, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEcx(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::ECX, 0xDEADBEEF);

        $this->executeBytes([0x51]); // PUSH ECX

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0xDEADBEEF, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEdx(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EDX, 0xCAFEBABE);

        $this->executeBytes([0x52]); // PUSH EDX

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0xCAFEBABE, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEbx(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);

        $this->executeBytes([0x53]); // PUSH EBX

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0xABCDEF00, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEsp(): void
    {
        // PUSH ESP pushes the value of ESP before the decrement
        $this->initStack(0x8000);

        $this->executeBytes([0x54]); // PUSH ESP

        $this->assertSame(0x7FFC, $this->getStackPointer());
        // Note: Intel's behavior is to push the value before decrement
        $this->assertSame(0x8000, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEbp(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EBP, 0x7FF0);

        $this->executeBytes([0x55]); // PUSH EBP

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x7FF0, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEsi(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::ESI, 0x11111111);

        $this->executeBytes([0x56]); // PUSH ESI

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x11111111, $this->readMemory(0x7FFC, 32));
    }

    public function testPushEdi(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EDI, 0x22222222);

        $this->executeBytes([0x57]); // PUSH EDI

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x22222222, $this->readMemory(0x7FFC, 32));
    }

    // ========================================
    // POP reg (0x58-0x5F) Tests
    // ========================================

    public function testPopEax(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0x12345678, 32);

        $this->executeBytes([0x58]); // POP EAX

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testPopEcx(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0xDEADBEEF, 32);

        $this->executeBytes([0x59]); // POP ECX

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::ECX));
    }

    public function testPopEdx(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0xCAFEBABE, 32);

        $this->executeBytes([0x5A]); // POP EDX

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0xCAFEBABE, $this->getRegister(RegisterType::EDX));
    }

    public function testPopEbx(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0xABCDEF00, 32);

        $this->executeBytes([0x5B]); // POP EBX

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0xABCDEF00, $this->getRegister(RegisterType::EBX));
    }

    public function testPopEsp(): void
    {
        // POP ESP sets ESP to the value popped from stack
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0x9000, 32);

        $this->executeBytes([0x5C]); // POP ESP

        $this->assertSame(0x9000, $this->getStackPointer());
    }

    public function testPopEbp(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0x7FF0, 32);

        $this->executeBytes([0x5D]); // POP EBP

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0x7FF0, $this->getRegister(RegisterType::EBP));
    }

    public function testPopEsi(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0x11111111, 32);

        $this->executeBytes([0x5E]); // POP ESI

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::ESI));
    }

    public function testPopEdi(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0x22222222, 32);

        $this->executeBytes([0x5F]); // POP EDI

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // PUSH/POP Roundtrip Tests
    // ========================================

    public function testPushPopRoundtrip(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0x00000000);

        // PUSH EAX
        $this->executeBytes([0x50]);
        $espAfterPush = $this->getStackPointer();

        // Reset memory stream and POP EBX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x5B)); // POP EBX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->popReg->process($this->runtime, 0x5B);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EBX));
        $this->assertSame(0x8000, $this->getStackPointer());
    }

    public function testMultiplePushPop(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::EBX, 0x22222222);
        $this->setRegister(RegisterType::ECX, 0x33333333);

        // PUSH EAX, PUSH EBX, PUSH ECX
        $this->executeBytes([0x50]); // PUSH EAX

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x53)); // PUSH EBX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->pushReg->process($this->runtime, 0x53);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x51)); // PUSH ECX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->pushReg->process($this->runtime, 0x51);

        // ESP should be decremented by 12 (3 * 4 bytes)
        $this->assertSame(0x7FF4, $this->getStackPointer());

        // POP in reverse order (ECX, EBX, EAX into EDX, ESI, EDI)
        $this->setRegister(RegisterType::EDX, 0);
        $this->setRegister(RegisterType::ESI, 0);
        $this->setRegister(RegisterType::EDI, 0);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x5A)); // POP EDX (gets ECX's value)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->popReg->process($this->runtime, 0x5A);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x5E)); // POP ESI (gets EBX's value)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->popReg->process($this->runtime, 0x5E);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x5F)); // POP EDI (gets EAX's value)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->popReg->process($this->runtime, 0x5F);

        $this->assertSame(0x33333333, $this->getRegister(RegisterType::EDX)); // ECX's value
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ESI)); // EBX's value
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EDI)); // EAX's value
        $this->assertSame(0x8000, $this->getStackPointer());
    }

    // ========================================
    // PUSH imm (0x68, 0x6A) Tests
    // ========================================

    public function testPushImm32(): void
    {
        $this->initStack(0x8000);

        // PUSH 0x12345678 (0x68 followed by dword)
        $this->executeBytes([0x68, 0x78, 0x56, 0x34, 0x12]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x12345678, $this->readMemory(0x7FFC, 32));
    }

    public function testPushImm8SignExtended(): void
    {
        $this->initStack(0x8000);

        // PUSH 0x7F (positive, sign-extended to 0x0000007F)
        $this->executeBytes([0x6A, 0x7F]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x0000007F, $this->readMemory(0x7FFC, 32));
    }

    public function testPushImm8SignExtendedNegative(): void
    {
        $this->initStack(0x8000);

        // PUSH 0xFF (-1 signed, sign-extended to 0xFFFFFFFF)
        $this->executeBytes([0x6A, 0xFF]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0xFFFFFFFF, $this->readMemory(0x7FFC, 32));
    }

    public function testPushImm8SignExtendedMinus128(): void
    {
        $this->initStack(0x8000);

        // PUSH 0x80 (-128 signed, sign-extended to 0xFFFFFF80)
        $this->executeBytes([0x6A, 0x80]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0xFFFFFF80, $this->readMemory(0x7FFC, 32));
    }

    // ========================================
    // PUSHA (0x60) Tests
    // ========================================

    public function testPusha(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::ECX, 0x22222222);
        $this->setRegister(RegisterType::EDX, 0x33333333);
        $this->setRegister(RegisterType::EBX, 0x44444444);
        // ESP = 0x8000
        $this->setRegister(RegisterType::EBP, 0x55555555);
        $this->setRegister(RegisterType::ESI, 0x66666666);
        $this->setRegister(RegisterType::EDI, 0x77777777);

        $this->executeBytes([0x60]); // PUSHA

        // ESP should be decremented by 32 (8 registers * 4 bytes)
        $this->assertSame(0x7FE0, $this->getStackPointer());

        // Verify stack contents (pushed in order: AX, CX, DX, BX, SP, BP, SI, DI)
        $this->assertSame(0x11111111, $this->readMemory(0x7FFC, 32)); // EAX
        $this->assertSame(0x22222222, $this->readMemory(0x7FF8, 32)); // ECX
        $this->assertSame(0x33333333, $this->readMemory(0x7FF4, 32)); // EDX
        $this->assertSame(0x44444444, $this->readMemory(0x7FF0, 32)); // EBX
        $this->assertSame(0x8000, $this->readMemory(0x7FEC, 32));     // ESP (original)
        $this->assertSame(0x55555555, $this->readMemory(0x7FE8, 32)); // EBP
        $this->assertSame(0x66666666, $this->readMemory(0x7FE4, 32)); // ESI
        $this->assertSame(0x77777777, $this->readMemory(0x7FE0, 32)); // EDI
    }

    // ========================================
    // POPA (0x61) Tests
    // ========================================

    public function testPopa(): void
    {
        $this->initStack(0x7FE0);

        // Set up stack contents (reverse order of PUSHA pop)
        $this->writeMemory(0x7FE0, 0x77777777, 32); // EDI
        $this->writeMemory(0x7FE4, 0x66666666, 32); // ESI
        $this->writeMemory(0x7FE8, 0x55555555, 32); // EBP
        $this->writeMemory(0x7FEC, 0xDEADDEAD, 32); // ESP (ignored)
        $this->writeMemory(0x7FF0, 0x44444444, 32); // EBX
        $this->writeMemory(0x7FF4, 0x33333333, 32); // EDX
        $this->writeMemory(0x7FF8, 0x22222222, 32); // ECX
        $this->writeMemory(0x7FFC, 0x11111111, 32); // EAX

        $this->executeBytes([0x61]); // POPA

        // ESP should be incremented by 32
        $this->assertSame(0x8000, $this->getStackPointer());

        // Verify registers
        $this->assertSame(0x77777777, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0x66666666, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x55555555, $this->getRegister(RegisterType::EBP));
        // ESP should NOT be restored from stack
        $this->assertSame(0x44444444, $this->getRegister(RegisterType::EBX));
        $this->assertSame(0x33333333, $this->getRegister(RegisterType::EDX));
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ECX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // PUSHA/POPA Roundtrip Test
    // ========================================

    public function testPushaPopaRoundtrip(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::ECX, 0x22222222);
        $this->setRegister(RegisterType::EDX, 0x33333333);
        $this->setRegister(RegisterType::EBX, 0x44444444);
        $this->setRegister(RegisterType::EBP, 0x55555555);
        $this->setRegister(RegisterType::ESI, 0x66666666);
        $this->setRegister(RegisterType::EDI, 0x77777777);

        // PUSHA
        $this->executeBytes([0x60]);

        // Clear all registers
        $this->setRegister(RegisterType::EAX, 0);
        $this->setRegister(RegisterType::ECX, 0);
        $this->setRegister(RegisterType::EDX, 0);
        $this->setRegister(RegisterType::EBX, 0);
        $this->setRegister(RegisterType::EBP, 0);
        $this->setRegister(RegisterType::ESI, 0);
        $this->setRegister(RegisterType::EDI, 0);

        // POPA
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x61)); // POPA
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->popa->process($this->runtime, 0x61);

        // Verify all registers restored
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ECX));
        $this->assertSame(0x33333333, $this->getRegister(RegisterType::EDX));
        $this->assertSame(0x44444444, $this->getRegister(RegisterType::EBX));
        $this->assertSame(0x55555555, $this->getRegister(RegisterType::EBP));
        $this->assertSame(0x66666666, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x77777777, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0x8000, $this->getStackPointer());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testPushWithZeroValue(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeBytes([0x50]); // PUSH EAX

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x00000000, $this->readMemory(0x7FFC, 32));
    }

    public function testPushWithMaxValue(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeBytes([0x50]); // PUSH EAX

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0xFFFFFFFF, $this->readMemory(0x7FFC, 32));
    }

    public function testPopDoesNotAffectFlags(): void
    {
        $this->initStack(0x7FFC);
        $this->writeMemory(0x7FFC, 0x00000000, 32);

        // Set flags to known state
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0x58]); // POP EAX (pops 0)

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    public function testPushDoesNotAffectFlags(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::EAX, 0x00000000);

        // Set flags to known state
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0x50]); // PUSH EAX

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    // ========================================
    // PUSHF (0x9C) Tests
    // ========================================

    public function testPushfPushesAllFlags(): void
    {
        $this->initStack(0x8000);

        // Set all testable flags
        $this->memoryAccessor->setCarryFlag(true);      // bit 0
        $this->memoryAccessor->setParityFlag(true);     // bit 2
        $this->memoryAccessor->setAuxiliaryCarryFlag(true); // bit 4
        $this->memoryAccessor->setZeroFlag(true);       // bit 6
        $this->memoryAccessor->setSignFlag(true);       // bit 7
        $this->memoryAccessor->setInterruptFlag(true);  // bit 9
        $this->memoryAccessor->setDirectionFlag(true);  // bit 10
        $this->memoryAccessor->setOverflowFlag(true);   // bit 11

        $this->executeBytes([0x9C]); // PUSHF

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $pushedFlags = $this->readMemory(0x7FFC, 32);

        // Check each flag bit
        $this->assertTrue(($pushedFlags & 0x0001) !== 0, 'CF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0002) !== 0, 'Reserved bit 1 should be set');
        $this->assertTrue(($pushedFlags & 0x0004) !== 0, 'PF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0010) !== 0, 'AF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0040) !== 0, 'ZF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0080) !== 0, 'SF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0200) !== 0, 'IF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0400) !== 0, 'DF should be set in pushed flags');
        $this->assertTrue(($pushedFlags & 0x0800) !== 0, 'OF should be set in pushed flags');
    }

    public function testPushfPushesOnlyAfFlag(): void
    {
        $this->initStack(0x8000);

        // Clear all flags, set only AF
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setParityFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setInterruptFlag(false);
        $this->memoryAccessor->setDirectionFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);

        $this->executeBytes([0x9C]); // PUSHF

        $pushedFlags = $this->readMemory(0x7FFC, 32);

        // Only AF and reserved bit should be set
        $this->assertFalse(($pushedFlags & 0x0001) !== 0, 'CF should be clear');
        $this->assertTrue(($pushedFlags & 0x0010) !== 0, 'AF should be set');
        $this->assertFalse(($pushedFlags & 0x0040) !== 0, 'ZF should be clear');
    }

    public function testPushfPushesClearedFlags(): void
    {
        $this->initStack(0x8000);

        // Clear all flags
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setParityFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setInterruptFlag(false);
        $this->memoryAccessor->setDirectionFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);

        $this->executeBytes([0x9C]); // PUSHF

        $pushedFlags = $this->readMemory(0x7FFC, 32);

        // Only reserved bit 1 should be set
        $this->assertSame(0x0002, $pushedFlags & 0x0FD7, 'Only reserved bit should be set (ignoring IOPL/NT)');
    }

    // ========================================
    // POPF (0x9D) Tests
    // ========================================

    public function testPopfRestoresAllFlags(): void
    {
        $this->initStack(0x7FFC);

        // Push flags value with all testable flags set
        // CF(1) | PF(4) | AF(16) | ZF(64) | SF(128) | IF(512) | DF(1024) | OF(2048)
        $flags = 0x0001 | 0x0002 | 0x0004 | 0x0010 | 0x0040 | 0x0080 | 0x0200 | 0x0400 | 0x0800;
        $this->writeMemory(0x7FFC, $flags, 32);

        // Clear all flags before POPF
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setParityFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->memoryAccessor->setDirectionFlag(false);
        $this->memoryAccessor->setInterruptFlag(false);

        $this->executeBytes([0x9D]); // POPF

        // Verify all flags are restored
        $this->assertTrue($this->getCarryFlag(), 'CF should be set');
        $this->assertTrue($this->memoryAccessor->shouldParityFlag(), 'PF should be set');
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag(), 'AF should be set');
        $this->assertTrue($this->getZeroFlag(), 'ZF should be set');
        $this->assertTrue($this->getSignFlag(), 'SF should be set');
        $this->assertTrue($this->getOverflowFlag(), 'OF should be set');
        $this->assertTrue($this->getDirectionFlag(), 'DF should be set');
    }

    public function testPopfClearsAllFlags(): void
    {
        $this->initStack(0x7FFC);

        // Push flags value with only reserved bit set
        $flags = 0x0002;
        $this->writeMemory(0x7FFC, $flags, 32);

        // Set all flags before POPF
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setParityFlag(true);
        $this->memoryAccessor->setAuxiliaryCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(true);
        $this->memoryAccessor->setDirectionFlag(true);

        $this->executeBytes([0x9D]); // POPF

        // Verify all flags are cleared
        $this->assertFalse($this->getCarryFlag(), 'CF should be clear');
        $this->assertFalse($this->memoryAccessor->shouldParityFlag(), 'PF should be clear');
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag(), 'AF should be clear');
        $this->assertFalse($this->getZeroFlag(), 'ZF should be clear');
        $this->assertFalse($this->getSignFlag(), 'SF should be clear');
        $this->assertFalse($this->getOverflowFlag(), 'OF should be clear');
        $this->assertFalse($this->getDirectionFlag(), 'DF should be clear');
    }

    public function testPopfRestoresOnlyAf(): void
    {
        $this->initStack(0x7FFC);

        // Push flags value with only AF set
        $flags = 0x0012; // reserved bit + AF
        $this->writeMemory(0x7FFC, $flags, 32);

        // Clear AF, set CF before POPF
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);
        $this->memoryAccessor->setCarryFlag(true);

        $this->executeBytes([0x9D]); // POPF

        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag(), 'AF should be set');
        $this->assertFalse($this->getCarryFlag(), 'CF should be clear');
    }

    public function testPushfPopfRoundtrip(): void
    {
        $this->initStack(0x8000);

        // Set specific flag pattern
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setParityFlag(false);
        $this->memoryAccessor->setAuxiliaryCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->memoryAccessor->setDirectionFlag(true);

        // PUSHF
        $this->executeBytes([0x9C]);

        // Change all flags
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setParityFlag(true);
        $this->memoryAccessor->setAuxiliaryCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(true);
        $this->memoryAccessor->setDirectionFlag(false);

        // POPF
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x9D));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->popf->process($this->runtime, 0x9D);

        // Verify original pattern is restored
        $this->assertTrue($this->getCarryFlag(), 'CF should be set');
        $this->assertFalse($this->memoryAccessor->shouldParityFlag(), 'PF should be clear');
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag(), 'AF should be set');
        $this->assertFalse($this->getZeroFlag(), 'ZF should be clear');
        $this->assertTrue($this->getSignFlag(), 'SF should be set');
        $this->assertFalse($this->getOverflowFlag(), 'OF should be clear');
        $this->assertTrue($this->getDirectionFlag(), 'DF should be set');
    }
}
