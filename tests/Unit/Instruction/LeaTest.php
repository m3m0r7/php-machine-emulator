<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Lea;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for LEA (Load Effective Address) instruction
 *
 * LEA r16, m / LEA r32, m: 0x8D
 *
 * LEA computes the effective address of the memory operand and stores it
 * in the register operand. It does NOT access memory.
 *
 * Real mode: 20-bit address mask (0xFFFFF)
 * Protected mode: 32-bit address mask (0xFFFFFFFF)
 */
class LeaTest extends InstructionTestCase
{
    private Lea $lea;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->lea = new Lea($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        if ($opcode === 0x8D) {
            return $this->lea;
        }
        return null;
    }

    // ========================================
    // Real Mode Tests (20-bit address mask with 32-bit addressing mode)
    // In real mode with address size override (0x67 prefix), 32-bit addressing
    // is available but the result is masked to 20 bits.
    // ========================================

    public function testLeaRealModeWithDisplacement(): void
    {
        // Real mode with 32-bit operand and address (simulating 0x66 + 0x67 prefix)
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);

        // LEA EAX, [disp32] - value within 20-bit range
        $this->executeBytes([0x8D, 0x05, 0x78, 0x56, 0x04, 0x00]); // disp = 0x00045678

        $this->assertSame(0x00045678, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaRealModeWithLargeDisplacementTruncated(): void
    {
        // Real mode uses 20-bit mask - values above 0xFFFFF are masked
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);

        // LEA EAX, [disp32] - value 0x12345678 will be masked to 0x45678
        $this->executeBytes([0x8D, 0x05, 0x78, 0x56, 0x34, 0x12]);

        // 0x12345678 & 0xFFFFF = 0x45678
        $this->assertSame(0x00045678, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaRealModeWraparound(): void
    {
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);

        // LEA with address overflow in real mode
        $this->setRegister(RegisterType::EBX, 0x000FFFF0);
        $this->executeBytes([0x8D, 0x43, 0x20]); // LEA EAX, [EBX+32]

        // 0x000FFFF0 + 32 = 0x100010 & 0xFFFFF = 0x00010
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // Protected Mode Tests (32-bit addressing)
    // ========================================

    public function testLeaProtectedModeWithDisplacement(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [disp32]
        // ModRM = 0x05 = 00 000 101 (mode=00, reg=EAX, r/m=101=disp32)
        // disp32 = 0x12345678
        $this->executeBytes([0x8D, 0x05, 0x78, 0x56, 0x34, 0x12]);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaProtectedModeWithLargeAddress(): void
    {
        $this->setProtectedMode(true);

        // LEA with result near 32-bit max
        $this->setRegister(RegisterType::EBX, 0xFFFFFFF0);
        $this->executeBytes([0x8D, 0x43, 0x0F]); // LEA EAX, [EBX+15]

        // 0xFFFFFFF0 + 15 = 0xFFFFFFFF
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaProtectedModeWraparound(): void
    {
        $this->setProtectedMode(true);

        // LEA with address overflow wraps around at 32-bit
        $this->setRegister(RegisterType::EBX, 0xFFFFFFF0);
        $this->executeBytes([0x8D, 0x43, 0x20]); // LEA EAX, [EBX+32]

        // 0xFFFFFFF0 + 32 = 0x100000010 & 0xFFFFFFFF = 0x00000010
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // LEA with base register (both modes)
    // ========================================

    public function testLeaWithBaseRegister(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+0]
        // ModRM = 0x43 = 01 000 011 (mode=01, reg=EAX, r/m=EBX)
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]

        $this->assertSame(0x00001000, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaWithBaseAndDisplacement8(): void
    {
        $this->setProtectedMode(true);

        // LEA ECX, [EBX+8]
        // ModRM = 0x4B = 01 001 011 (mode=01, reg=ECX, r/m=EBX)
        $this->setRegister(RegisterType::EBX, 0x00002000);
        $this->executeBytes([0x8D, 0x4B, 0x08]); // LEA ECX, [EBX+8]

        $this->assertSame(0x00002008, $this->getRegister(RegisterType::ECX));
    }

    public function testLeaWithBaseAndNegativeDisplacement(): void
    {
        $this->setProtectedMode(true);

        // LEA EDX, [EBX-16]
        // ModRM = 0x53 = 01 010 011 (mode=01, reg=EDX, r/m=EBX)
        // disp8 = 0xF0 = -16
        $this->setRegister(RegisterType::EBX, 0x00003000);
        $this->executeBytes([0x8D, 0x53, 0xF0]); // LEA EDX, [EBX-16]

        $this->assertSame(0x00002FF0, $this->getRegister(RegisterType::EDX));
    }

    public function testLeaWithBaseAndDisplacement32(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+0x1000]
        // ModRM = 0x83 = 10 000 011 (mode=10, reg=EAX, r/m=EBX)
        $this->setRegister(RegisterType::EBX, 0x00005000);
        $this->executeBytes([0x8D, 0x83, 0x00, 0x10, 0x00, 0x00]); // LEA EAX, [EBX+0x1000]

        $this->assertSame(0x00006000, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // LEA with SIB byte Tests
    // ========================================

    public function testLeaWithSibBaseOnly(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [ESP+0]
        // ModRM = 0x44 = 01 000 100 (mode=01, reg=EAX, r/m=100=SIB)
        // SIB = 0x24 = 00 100 100 (scale=0, index=ESP=none, base=ESP)
        $this->setRegister(RegisterType::ESP, 0x00007000);
        $this->executeBytes([0x8D, 0x44, 0x24, 0x00]); // LEA EAX, [ESP+0]

        $this->assertSame(0x00007000, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaWithSibBaseAndIndex(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX+0]
        // ModRM = 0x44 = 01 000 100 (mode=01, reg=EAX, r/m=100=SIB)
        // SIB = 0x0B = 00 001 011 (scale=1, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->setRegister(RegisterType::ECX, 0x00000100);
        $this->executeBytes([0x8D, 0x44, 0x0B, 0x00]); // LEA EAX, [EBX+ECX+0]

        $this->assertSame(0x00001100, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaWithSibScaledIndex(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*4+0]
        // ModRM = 0x44 = 01 000 100 (mode=01, reg=EAX, r/m=100=SIB)
        // SIB = 0x8B = 10 001 011 (scale=4, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->setRegister(RegisterType::ECX, 0x00000100);
        $this->executeBytes([0x8D, 0x44, 0x8B, 0x00]); // LEA EAX, [EBX+ECX*4+0]

        // 0x1000 + 0x100 * 4 = 0x1000 + 0x400 = 0x1400
        $this->assertSame(0x00001400, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaWithSibScaledIndexAndDisplacement(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*4+0x10]
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->setRegister(RegisterType::ECX, 0x00000100);
        $this->executeBytes([0x8D, 0x44, 0x8B, 0x10]); // LEA EAX, [EBX+ECX*4+16]

        // 0x1000 + 0x100 * 4 + 0x10 = 0x1410
        $this->assertSame(0x00001410, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaWithSibScale8(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*8+0]
        // SIB = 0xCB = 11 001 011 (scale=8, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->setRegister(RegisterType::ECX, 0x00000100);
        $this->executeBytes([0x8D, 0x44, 0xCB, 0x00]); // LEA EAX, [EBX+ECX*8+0]

        // 0x1000 + 0x100 * 8 = 0x1000 + 0x800 = 0x1800
        $this->assertSame(0x00001800, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // Flag Tests
    // ========================================

    public function testLeaDoesNotAffectFlags(): void
    {
        $this->setProtectedMode(true);

        // LEA should not affect any flags
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);

        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]

        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testLeaPreservesClearFlags(): void
    {
        $this->setProtectedMode(true);

        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);

        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]

        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testLeaWithZeroBase(): void
    {
        $this->setProtectedMode(true);

        $this->setRegister(RegisterType::EBX, 0x00000000);
        $this->executeBytes([0x8D, 0x43, 0x10]); // LEA EAX, [EBX+16]

        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaWithZeroIndex(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*4+0] where ECX=0
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0x8D, 0x44, 0x8B, 0x00]); // LEA EAX, [EBX+ECX*4+0]

        // 0x1000 + 0 * 4 = 0x1000
        $this->assertSame(0x00001000, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaDoesNotReadMemory(): void
    {
        $this->setProtectedMode(true);

        // LEA should compute address but NOT read from memory
        // Write some value to memory that should NOT be fetched
        $this->writeMemory(0x00001000, 0xDEADBEEF, 32);

        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]

        // EAX should contain the ADDRESS, not the memory content
        $this->assertSame(0x00001000, $this->getRegister(RegisterType::EAX));
        // NOT 0xDEADBEEF
    }

    public function testLeaToDifferentRegisters(): void
    {
        $this->setProtectedMode(true);

        $testCases = [
            // [dest_reg, modrm_byte, expected_value]
            [RegisterType::EAX, 0x43, 0x00001000], // LEA EAX, [EBX+0]
            [RegisterType::ECX, 0x4B, 0x00001000], // LEA ECX, [EBX+0]
            [RegisterType::EDX, 0x53, 0x00001000], // LEA EDX, [EBX+0]
            [RegisterType::ESI, 0x73, 0x00001000], // LEA ESI, [EBX+0]
            [RegisterType::EDI, 0x7B, 0x00001000], // LEA EDI, [EBX+0]
        ];

        foreach ($testCases as [$destReg, $modrm, $expected]) {
            $this->setUp(); // Reset state
            $this->setProtectedMode(true);
            $this->setRegister(RegisterType::EBX, 0x00001000);
            $this->executeBytes([0x8D, $modrm, 0x00]);

            $this->assertSame(
                $expected,
                $this->getRegister($destReg),
                "LEA to {$destReg->name} failed"
            );
        }
    }

    // ========================================
    // Mode Comparison Tests
    // ========================================

    public function testLeaSameAddressRealVsProtectedMode(): void
    {
        // Test same address produces same result in both modes (within 20-bit range)
        $address = 0x00012345;

        // Real mode with 32-bit operand and addressing (simulating 0x66 + 0x67 prefix)
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::EBX, $address);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]
        $realModeResult = $this->getRegister(RegisterType::EAX);

        // Protected mode
        $this->setUp();
        $this->setProtectedMode(true);
        $this->setRegister(RegisterType::EBX, $address);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]
        $protectedModeResult = $this->getRegister(RegisterType::EAX);

        // Both should match for addresses within 20-bit range
        $this->assertSame($realModeResult, $protectedModeResult);
        $this->assertSame($address, $realModeResult);
    }

    public function testLeaHighAddressDiffersBetweenModes(): void
    {
        // Test that high addresses differ between modes
        $address = 0x12345678;

        // Real mode with 32-bit operand and addressing - should be masked to 20 bits
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::EBX, $address);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]
        $realModeResult = $this->getRegister(RegisterType::EAX);

        // Protected mode - should keep full 32 bits
        $this->setUp();
        $this->setProtectedMode(true);
        $this->setRegister(RegisterType::EBX, $address);
        $this->executeBytes([0x8D, 0x43, 0x00]); // LEA EAX, [EBX+0]
        $protectedModeResult = $this->getRegister(RegisterType::EAX);

        // Real mode should be masked
        $this->assertSame($address & 0xFFFFF, $realModeResult);
        // Protected mode should be full value
        $this->assertSame($address, $protectedModeResult);
        // They should differ for high addresses
        $this->assertNotSame($realModeResult, $protectedModeResult);
    }

    // ========================================
    // SIB with Large/Negative Index Tests (32-bit wraparound)
    // ========================================

    public function testLeaSibWithLargeIndex(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EDI+EDX*1] where EDX has a large value (simulating NEG result)
        // This tests the case from LZMA decoder: NEG EDX gives 0xFFD158A4
        // ModRM = 0x04 = 00 000 100 (mode=00, reg=EAX, r/m=100=SIB)
        // SIB = 0x17 = 00 010 111 (scale=1, index=EDX, base=EDI)
        $this->setRegister(RegisterType::EDI, 0x0010012A);
        $this->setRegister(RegisterType::EDX, 0xFFD158A4); // -0x2EA75C in two's complement

        $this->executeBytes([0x8D, 0x04, 0x17]); // LEA EAX, [EDI+EDX*1]

        // 0x0010012A + 0xFFD158A4 = 0xFFE159CE (wraps at 32-bit)
        $this->assertSame(0xFFE159CE, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaSibWithLargeIndexScale2(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*2] with large index
        $this->setRegister(RegisterType::EBX, 0x00001000);
        $this->setRegister(RegisterType::ECX, 0x80000000); // Large value

        // SIB = 0x4B = 01 001 011 (scale=2, index=ECX, base=EBX)
        $this->executeBytes([0x8D, 0x04, 0x4B]); // LEA EAX, [EBX+ECX*2]

        // 0x1000 + 0x80000000 * 2 = 0x1000 + 0x100000000 = 0x00001000 (wraps)
        $this->assertSame(0x00001000, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaSibWithLargeIndexScale4(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*4] with large index causing overflow
        $this->setRegister(RegisterType::EBX, 0x00000100);
        $this->setRegister(RegisterType::ECX, 0x40000000); // 0x40000000 * 4 = 0x100000000 = 0

        // SIB = 0x8B = 10 001 011 (scale=4, index=ECX, base=EBX)
        $this->executeBytes([0x8D, 0x04, 0x8B]); // LEA EAX, [EBX+ECX*4]

        // 0x100 + 0x40000000 * 4 = 0x100 + 0 = 0x100
        $this->assertSame(0x00000100, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaSibWithNegativeStyleIndex(): void
    {
        $this->setProtectedMode(true);

        // Simulate backward reference: base - offset
        // Using NEG result as index: base + (-offset) = base - offset
        $this->setRegister(RegisterType::EBX, 0x00010000); // Base = 0x10000
        $this->setRegister(RegisterType::ECX, 0xFFFFFF00); // -256 in two's complement

        // LEA EAX, [EBX+ECX*1]
        // SIB = 0x0B = 00 001 011 (scale=1, index=ECX, base=EBX)
        $this->executeBytes([0x8D, 0x04, 0x0B]);

        // 0x10000 + 0xFFFFFF00 = 0x10000FF00 & 0xFFFFFFFF = 0x0000FF00
        $this->assertSame(0x0000FF00, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaSibOverflowWrapsCorrectly(): void
    {
        $this->setProtectedMode(true);

        // Test that address calculation wraps correctly at 32-bit boundary
        $this->setRegister(RegisterType::EBX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::ECX, 0x00000002);

        // LEA EAX, [EBX+ECX*1]
        // SIB = 0x0B = 00 001 011 (scale=1, index=ECX, base=EBX)
        $this->executeBytes([0x8D, 0x04, 0x0B]);

        // 0xFFFFFFFF + 2 = 0x100000001 & 0xFFFFFFFF = 0x00000001
        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaSibWithDisplacementOverflow(): void
    {
        $this->setProtectedMode(true);

        // LEA EAX, [EBX+ECX*1+disp8] with overflow
        $this->setRegister(RegisterType::EBX, 0xFFFFFFF0);
        $this->setRegister(RegisterType::ECX, 0x00000000);

        // ModRM = 0x44 = 01 000 100 (mode=01=disp8, reg=EAX, r/m=100=SIB)
        // SIB = 0x0B = 00 001 011 (scale=1, index=ECX, base=EBX)
        // disp8 = 0x20 = 32
        $this->executeBytes([0x8D, 0x44, 0x0B, 0x20]);

        // 0xFFFFFFF0 + 0 + 32 = 0x100000010 & 0xFFFFFFFF = 0x00000010
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX));
    }

    public function testLeaRealModeSibWithLargeIndexMasked(): void
    {
        // In real mode, even with 32-bit addressing, result is masked to 20 bits
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);

        $this->setRegister(RegisterType::EDI, 0x0010012A);
        $this->setRegister(RegisterType::EDX, 0xFFD158A4);

        // LEA EAX, [EDI+EDX*1]
        $this->executeBytes([0x8D, 0x04, 0x17]);

        // (0x0010012A + 0xFFD158A4) & 0xFFFFF = 0xFFE159CE & 0xFFFFF = 0xE159CE
        // Wait - the value is 0xFFE159CE, masked to 20 bits = 0xE159CE
        // Actually 0xFFE159CE & 0xFFFFF = 0xE159CE... let me check
        // 0xFFE159CE = 1111 1111 1110 0001 0101 1001 1100 1110
        // & 0x000FFFFF = 0000 0000 0000 1111 1111 1111 1111 1111
        // = 0x000159CE
        $this->assertSame(0x000159CE, $this->getRegister(RegisterType::EAX));
    }
}
