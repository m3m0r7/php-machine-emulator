<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\PushReg;
use PHPMachineEmulator\Instruction\Intel\x86\PopReg;
use PHPMachineEmulator\Instruction\Intel\x86\PushImm;
use PHPMachineEmulator\Instruction\Intel\x86\Pusha;
use PHPMachineEmulator\Instruction\Intel\x86\Popa;
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
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match (true) {
            $opcode >= 0x50 && $opcode <= 0x57 => $this->pushReg,
            $opcode >= 0x58 && $opcode <= 0x5F => $this->popReg,
            $opcode === 0x68 || $opcode === 0x6A => $this->pushImm,
            $opcode === 0x60 => $this->pusha,
            $opcode === 0x61 => $this->popa,
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
}
