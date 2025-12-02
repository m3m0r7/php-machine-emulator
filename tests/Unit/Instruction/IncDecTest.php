<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Inc;
use PHPMachineEmulator\Instruction\Intel\x86\Dec;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for INC and DEC instructions
 *
 * INC reg opcodes: 0x40-0x47 (EAX, ECX, EDX, EBX, ESP, EBP, ESI, EDI)
 * DEC reg opcodes: 0x48-0x4F (EAX, ECX, EDX, EBX, ESP, EBP, ESI, EDI)
 *
 * Important: INC/DEC do NOT affect the Carry Flag (CF)
 */
class IncDecTest extends InstructionTestCase
{
    private Inc $inc;
    private Dec $dec;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->inc = new Inc($instructionList);
        $this->dec = new Dec($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match (true) {
            $opcode >= 0x40 && $opcode <= 0x47 => $this->inc,
            $opcode >= 0x48 && $opcode <= 0x4F => $this->dec,
            default => null,
        };
    }

    // ========================================
    // INC reg (0x40-0x47) Tests
    // ========================================

    public function testIncEax(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x40]); // INC EAX

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
    }

    public function testIncEcx(): void
    {
        $this->setRegister(RegisterType::ECX, 0x12345678);
        $this->executeBytes([0x41]); // INC ECX

        $this->assertSame(0x12345679, $this->getRegister(RegisterType::ECX));
    }

    public function testIncEdx(): void
    {
        $this->setRegister(RegisterType::EDX, 0xFFFFFFFE);
        $this->executeBytes([0x42]); // INC EDX

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EDX));
    }

    public function testIncEbx(): void
    {
        $this->setRegister(RegisterType::EBX, 0x000000FF);
        $this->executeBytes([0x43]); // INC EBX

        $this->assertSame(0x00000100, $this->getRegister(RegisterType::EBX));
    }

    public function testIncEsp(): void
    {
        $this->setRegister(RegisterType::ESP, 0x00008000);
        $this->executeBytes([0x44]); // INC ESP

        $this->assertSame(0x00008001, $this->getRegister(RegisterType::ESP));
    }

    public function testIncEbp(): void
    {
        $this->setRegister(RegisterType::EBP, 0x00007FFF);
        $this->executeBytes([0x45]); // INC EBP

        $this->assertSame(0x00008000, $this->getRegister(RegisterType::EBP));
    }

    public function testIncEsi(): void
    {
        $this->setRegister(RegisterType::ESI, 0x11111111);
        $this->executeBytes([0x46]); // INC ESI

        $this->assertSame(0x11111112, $this->getRegister(RegisterType::ESI));
    }

    public function testIncEdi(): void
    {
        $this->setRegister(RegisterType::EDI, 0x22222222);
        $this->executeBytes([0x47]); // INC EDI

        $this->assertSame(0x22222223, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // DEC reg (0x48-0x4F) Tests
    // ========================================

    public function testDecEax(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x48]); // DEC EAX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
    }

    public function testDecEcx(): void
    {
        $this->setRegister(RegisterType::ECX, 0x12345678);
        $this->executeBytes([0x49]); // DEC ECX

        $this->assertSame(0x12345677, $this->getRegister(RegisterType::ECX));
    }

    public function testDecEdx(): void
    {
        $this->setRegister(RegisterType::EDX, 0x00000100);
        $this->executeBytes([0x4A]); // DEC EDX

        $this->assertSame(0x000000FF, $this->getRegister(RegisterType::EDX));
    }

    public function testDecEbx(): void
    {
        $this->setRegister(RegisterType::EBX, 0x00000000);
        $this->executeBytes([0x4B]); // DEC EBX

        // 0 - 1 = 0xFFFFFFFF (underflow/wrap)
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EBX));
    }

    public function testDecEsp(): void
    {
        $this->setRegister(RegisterType::ESP, 0x00008000);
        $this->executeBytes([0x4C]); // DEC ESP

        $this->assertSame(0x00007FFF, $this->getRegister(RegisterType::ESP));
    }

    public function testDecEbp(): void
    {
        $this->setRegister(RegisterType::EBP, 0x00008000);
        $this->executeBytes([0x4D]); // DEC EBP

        $this->assertSame(0x00007FFF, $this->getRegister(RegisterType::EBP));
    }

    public function testDecEsi(): void
    {
        $this->setRegister(RegisterType::ESI, 0x11111111);
        $this->executeBytes([0x4E]); // DEC ESI

        $this->assertSame(0x11111110, $this->getRegister(RegisterType::ESI));
    }

    public function testDecEdi(): void
    {
        $this->setRegister(RegisterType::EDI, 0x22222222);
        $this->executeBytes([0x4F]); // DEC EDI

        $this->assertSame(0x22222221, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // Flag Tests
    // ========================================

    public function testIncSetsZeroFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x40]); // INC EAX

        // 0xFFFFFFFF + 1 = 0x00000000 (overflow)
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag());
    }

    public function testDecSetsZeroFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x48]); // DEC EAX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag());
    }

    public function testIncClearsZeroFlag(): void
    {
        $this->memoryAccessor->setZeroFlag(true);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x40]); // INC EAX

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getZeroFlag());
    }

    public function testIncSetsSignFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF);
        $this->executeBytes([0x40]); // INC EAX

        // 0x7FFFFFFF + 1 = 0x80000000 (negative in signed)
        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getSignFlag());
    }

    public function testDecSetsSignFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x48]); // DEC EAX

        // 0 - 1 = 0xFFFFFFFF (negative in signed)
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getSignFlag());
    }

    public function testDecClearsSignFlag(): void
    {
        $this->setRegister(RegisterType::EAX, 0x80000001);
        $this->executeBytes([0x48]); // DEC EAX

        // 0x80000001 - 1 = 0x80000000 (still negative)
        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getSignFlag()); // still negative
    }

    // INC/DEC should NOT affect Carry Flag
    public function testIncDoesNotAffectCarryFlag(): void
    {
        // Set carry flag before INC
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x40]); // INC EAX (causes overflow)

        // Carry flag should remain set (INC doesn't touch CF)
        $this->assertTrue($this->getCarryFlag());
    }

    public function testDecDoesNotAffectCarryFlag(): void
    {
        // Clear carry flag before DEC
        $this->setCarryFlag(false);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x48]); // DEC EAX (causes underflow)

        // Carry flag should remain clear (DEC doesn't touch CF)
        $this->assertFalse($this->getCarryFlag());
    }

    public function testIncPreservesCarryFlagWhenClear(): void
    {
        $this->setCarryFlag(false);
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x40]); // INC EAX

        $this->assertFalse($this->getCarryFlag());
    }

    public function testDecPreservesCarryFlagWhenSet(): void
    {
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x48]); // DEC EAX

        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testIncMaxValue(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x40]); // INC EAX

        // Should wrap to 0
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
    }

    public function testDecMinValue(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x48]); // DEC EAX

        // Should wrap to max
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testIncDecRoundtrip(): void
    {
        $originalValue = 0x12345678;
        $this->setRegister(RegisterType::EAX, $originalValue);

        // INC then DEC should return to original value
        $this->executeBytes([0x40]); // INC EAX
        $this->assertSame($originalValue + 1, $this->getRegister(RegisterType::EAX));

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x48)); // DEC EAX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->dec->process($this->runtime, 0x48);

        $this->assertSame($originalValue, $this->getRegister(RegisterType::EAX));
    }

    public function testDecIncRoundtrip(): void
    {
        $originalValue = 0x12345678;
        $this->setRegister(RegisterType::EAX, $originalValue);

        // DEC then INC should return to original value
        $this->executeBytes([0x48]); // DEC EAX
        $this->assertSame($originalValue - 1, $this->getRegister(RegisterType::EAX));

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x40)); // INC EAX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->inc->process($this->runtime, 0x40);

        $this->assertSame($originalValue, $this->getRegister(RegisterType::EAX));
    }

    public function testMultipleIncrements(): void
    {
        $this->setRegister(RegisterType::EAX, 0);

        for ($i = 0; $i < 10; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0x40));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->inc->process($this->runtime, 0x40);
        }

        $this->assertSame(10, $this->getRegister(RegisterType::EAX));
    }

    public function testMultipleDecrements(): void
    {
        $this->setRegister(RegisterType::EAX, 10);

        for ($i = 0; $i < 10; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0x48));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->dec->process($this->runtime, 0x48);
        }

        $this->assertSame(0, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getZeroFlag());
    }

    // ========================================
    // Overflow Flag Tests
    // ========================================

    /**
     * INC sets OF when result is 0x80000000 (incrementing 0x7FFFFFFF to 0x80000000)
     * This is the max positive value becoming the min negative value
     */
    public function testIncSetsOverflowFlagOnPositiveToNegative(): void
    {
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF);
        $this->executeBytes([0x40]); // INC EAX

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getOverflowFlag());
    }

    /**
     * INC does NOT set OF for normal increments
     */
    public function testIncClearsOverflowFlagNormalCase(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->executeBytes([0x40]); // INC EAX

        $this->assertSame(0x00000002, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * INC does NOT set OF when wrapping from 0xFFFFFFFF to 0x00000000
     * This is unsigned overflow, not signed overflow
     */
    public function testIncDoesNotSetOverflowFlagOnWrap(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0x40]); // INC EAX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * DEC sets OF when result is 0x7FFFFFFF (decrementing 0x80000000 to 0x7FFFFFFF)
     * This is the min negative value becoming the max positive value
     */
    public function testDecSetsOverflowFlagOnNegativeToPositive(): void
    {
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->executeBytes([0x48]); // DEC EAX

        $this->assertSame(0x7FFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertTrue($this->getOverflowFlag());
    }

    /**
     * DEC does NOT set OF for normal decrements
     */
    public function testDecClearsOverflowFlagNormalCase(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000002);
        $this->executeBytes([0x48]); // DEC EAX

        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * DEC does NOT set OF when wrapping from 0x00000000 to 0xFFFFFFFF
     * This is unsigned underflow, not signed overflow
     */
    public function testDecDoesNotSetOverflowFlagOnWrap(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x48]); // DEC EAX

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * INC in 16-bit mode sets OF when result is 0x8000
     */
    public function testIncSetsOverflowFlag16Bit(): void
    {
        // Set operand size to 16-bit
        $this->cpuContext->setDefaultOperandSize(16);

        $this->setRegister(RegisterType::EAX, 0x7FFF);
        $this->executeBytes([0x40]); // INC AX

        $this->assertSame(0x8000, $this->getRegister(RegisterType::EAX) & 0xFFFF);
        $this->assertTrue($this->getOverflowFlag());
    }

    /**
     * DEC in 16-bit mode sets OF when result is 0x7FFF
     */
    public function testDecSetsOverflowFlag16Bit(): void
    {
        // Set operand size to 16-bit
        $this->cpuContext->setDefaultOperandSize(16);

        $this->setRegister(RegisterType::EAX, 0x8000);
        $this->executeBytes([0x48]); // DEC AX

        $this->assertSame(0x7FFF, $this->getRegister(RegisterType::EAX) & 0xFFFF);
        $this->assertTrue($this->getOverflowFlag());
    }
}
