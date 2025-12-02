<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Shld;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Shrd;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for SHLD and SHRD instructions.
 *
 * SHLD r/m16/32, r16/32, imm8: 0x0F 0xA4
 * SHLD r/m16/32, r16/32, CL: 0x0F 0xA5
 * SHRD r/m16/32, r16/32, imm8: 0x0F 0xAC
 * SHRD r/m16/32, r16/32, CL: 0x0F 0xAD
 */
class ShldShrdTest extends TwoByteOpTestCase
{
    private Shld $shld;
    private Shrd $shrd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shld = new Shld($this->instructionList);
        $this->shrd = new Shrd($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->shld;
    }

    // ========================================
    // SHLD r32, r32, imm8 (0x0F 0xA4) Tests
    // ========================================

    public function testShldR32R32Imm8(): void
    {
        // SHLD EAX, EBX, 4
        // Shifts EAX left by 4, fills low bits from EBX high bits
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);

        // ModRM = 0xD8 = 11 011 000 (reg=EBX, r/m=EAX)
        $this->executeShldImm([0xD8, 0x04]);

        // EAX << 4, filled with high 4 bits of EBX (0xA)
        // 0x12345678 << 4 = 0x23456780
        // Fill with 0xA from EBX = 0x2345678A
        $this->assertSame(0x2345678A, $this->getRegister(RegisterType::EAX));
    }

    public function testShldR32R32By8(): void
    {
        // SHLD EAX, EBX, 8
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);

        $this->executeShldImm([0xD8, 0x08]);

        // 0x12345678 << 8 = 0x34567800
        // Fill with high 8 bits of EBX (0xAB) = 0x345678AB
        $this->assertSame(0x345678AB, $this->getRegister(RegisterType::EAX));
    }

    public function testShldR32R32By16(): void
    {
        // SHLD EAX, EBX, 16
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);

        $this->executeShldImm([0xD8, 0x10]);

        // 0x12345678 << 16 = 0x56780000
        // Fill with high 16 bits of EBX (0xABCD) = 0x5678ABCD
        $this->assertSame(0x5678ABCD, $this->getRegister(RegisterType::EAX));
    }

    public function testShldBy0(): void
    {
        // SHLD EAX, EBX, 0 - no change
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xFFFFFFFF);

        $this->executeShldImm([0xD8, 0x00]);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // SHLD r32, r32, CL (0x0F 0xA5) Tests
    // ========================================

    public function testShldR32R32Cl(): void
    {
        // SHLD EAX, EBX, CL
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);
        $this->setRegister(RegisterType::ECX, 0x00000004); // CL = 4

        $this->executeShldCl([0xD8]);

        $this->assertSame(0x2345678A, $this->getRegister(RegisterType::EAX));
    }

    public function testShldWithClMasked(): void
    {
        // SHLD with CL > 31 - should be masked to 5 bits
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);
        $this->setRegister(RegisterType::ECX, 0x00000024); // CL = 36, masked to 4

        $this->executeShldCl([0xD8]);

        $this->assertSame(0x2345678A, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // SHRD r32, r32, imm8 (0x0F 0xAC) Tests
    // ========================================

    public function testShrdR32R32Imm8(): void
    {
        // SHRD EAX, EBX, 4
        // Shifts EAX right by 4, fills high bits from EBX low bits
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0x0000000F);

        $this->executeShrdImm([0xD8, 0x04]);

        // EAX >> 4 = 0x01234567
        // Fill with low 4 bits of EBX (0xF) = 0xF1234567
        $this->assertSame(0xF1234567, $this->getRegister(RegisterType::EAX));
    }

    public function testShrdR32R32By8(): void
    {
        // SHRD EAX, EBX, 8
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0x000000AB);

        $this->executeShrdImm([0xD8, 0x08]);

        // 0x12345678 >> 8 = 0x00123456
        // Fill with low 8 bits of EBX (0xAB) = 0xAB123456
        $this->assertSame(0xAB123456, $this->getRegister(RegisterType::EAX));
    }

    public function testShrdR32R32By16(): void
    {
        // SHRD EAX, EBX, 16
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0x0000ABCD);

        $this->executeShrdImm([0xD8, 0x10]);

        // 0x12345678 >> 16 = 0x00001234
        // Fill with low 16 bits of EBX (0xABCD) = 0xABCD1234
        $this->assertSame(0xABCD1234, $this->getRegister(RegisterType::EAX));
    }

    public function testShrdBy0(): void
    {
        // SHRD EAX, EBX, 0 - no change
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0xFFFFFFFF);

        $this->executeShrdImm([0xD8, 0x00]);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // SHRD r32, r32, CL (0x0F 0xAD) Tests
    // ========================================

    public function testShrdR32R32Cl(): void
    {
        // SHRD EAX, EBX, CL
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0x0000000F);
        $this->setRegister(RegisterType::ECX, 0x00000004); // CL = 4

        $this->executeShrdCl([0xD8]);

        $this->assertSame(0xF1234567, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // Memory Operand Tests
    // ========================================

    public function testShldMemR32Imm8(): void
    {
        // SHLD [0x1000], EBX, 4
        $this->writeMemory(0x1000, 0x12345678, 32);
        $this->setRegister(RegisterType::EBX, 0xABCDEF00);

        // ModRM = 0x1D = 00 011 101 (reg=EBX, r/m=disp32)
        $this->executeShldImm([0x1D, 0x00, 0x10, 0x00, 0x00, 0x04]);

        $this->assertSame(0x2345678A, $this->readMemory(0x1000, 32));
    }

    public function testShrdMemR32Imm8(): void
    {
        // SHRD [0x1000], EBX, 4
        $this->writeMemory(0x1000, 0x12345678, 32);
        $this->setRegister(RegisterType::EBX, 0x0000000F);

        $this->executeShrdImm([0x1D, 0x00, 0x10, 0x00, 0x00, 0x04]);

        $this->assertSame(0xF1234567, $this->readMemory(0x1000, 32));
    }

    // ========================================
    // Flag Tests
    // ========================================

    public function testShldSetsCarryFlag(): void
    {
        // SHLD EAX, EBX, 1 where MSB of EAX is 1
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->setRegister(RegisterType::EBX, 0x00000000);

        $this->executeShldImm([0xD8, 0x01]);

        $this->assertTrue($this->getCarryFlag());
    }

    public function testShrdSetsCarryFlag(): void
    {
        // SHRD EAX, EBX, 1 where LSB of EAX is 1
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::EBX, 0x00000000);

        $this->executeShrdImm([0xD8, 0x01]);

        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // Helper methods
    // ========================================

    private function executeShldImm(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xA4;
        $this->shld->process($this->runtime, $opcodeKey);
    }

    private function executeShldCl(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xA5;
        $this->shld->process($this->runtime, $opcodeKey);
    }

    private function executeShrdImm(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xAC;
        $this->shrd->process($this->runtime, $opcodeKey);
    }

    private function executeShrdCl(array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | 0xAD;
        $this->shrd->process($this->runtime, $opcodeKey);
    }
}
