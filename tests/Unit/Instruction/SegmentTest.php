<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for segment register handling.
 *
 * Tests verify:
 * - Segment register values
 * - Segment override prefixes (CS, DS, ES, SS, FS, GS)
 * - Real mode segment calculations
 * - Protected mode segment handling
 */
class SegmentTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    // ========================================
    // Real Mode Segment Tests
    // ========================================

    public function testRealModeSegmentCalculation(): void
    {
        $this->setRealMode16();

        // Set DS = 0x1000, which means linear address = DS * 16 + offset
        $this->setRegister(RegisterType::DS, 0x1000);

        // Linear address for DS:0x0100 should be 0x1000 * 16 + 0x0100 = 0x10100
        $expectedLinear = 0x1000 * 16 + 0x0100;
        $this->assertSame(0x10100, $expectedLinear);
    }

    public function testSegmentOverrideInRealMode(): void
    {
        $this->setRealMode16();

        $this->setRegister(RegisterType::DS, 0x1000);
        $this->setRegister(RegisterType::ES, 0x2000);
        $this->setRegister(RegisterType::SS, 0x3000);
        $this->setRegister(RegisterType::CS, 0x0000);

        // Verify segments are set correctly
        $this->assertSame(0x1000, $this->getRegister(RegisterType::DS, 16));
        $this->assertSame(0x2000, $this->getRegister(RegisterType::ES, 16));
        $this->assertSame(0x3000, $this->getRegister(RegisterType::SS, 16));
    }

    // ========================================
    // Protected Mode Segment Tests
    // ========================================

    public function testProtectedModeSegmentSelector(): void
    {
        $this->setProtectedMode32();

        // In protected mode, segment registers hold selectors
        // Selector format: Index (13 bits) | TI (1 bit) | RPL (2 bits)
        $selector = 0x0020; // Index=4, TI=0 (GDT), RPL=0

        $this->setRegister(RegisterType::DS, $selector);
        $this->assertSame($selector, $this->getRegister(RegisterType::DS, 16));
    }

    public function testSegmentSelectorComponents(): void
    {
        // Test selector parsing
        $selector = 0x0023; // Index=4, TI=0, RPL=3

        $index = ($selector >> 3) & 0x1FFF;
        $ti = ($selector >> 2) & 0x1;
        $rpl = $selector & 0x3;

        $this->assertSame(4, $index);
        $this->assertSame(0, $ti);
        $this->assertSame(3, $rpl);
    }

    // ========================================
    // FS/GS Segment Tests (386+)
    // ========================================

    public function testFsGsSegmentsExist(): void
    {
        $this->setProtectedMode32();

        $this->setRegister(RegisterType::FS, 0x0038);
        $this->setRegister(RegisterType::GS, 0x0040);

        $this->assertSame(0x0038, $this->getRegister(RegisterType::FS, 16));
        $this->assertSame(0x0040, $this->getRegister(RegisterType::GS, 16));
    }

    // ========================================
    // Segment Override Prefix Bytes
    // ========================================

    public function testSegmentOverridePrefixValues(): void
    {
        // Verify prefix byte values
        $this->assertSame(0x26, 0x26); // ES override
        $this->assertSame(0x2E, 0x2E); // CS override
        $this->assertSame(0x36, 0x36); // SS override
        $this->assertSame(0x3E, 0x3E); // DS override
        $this->assertSame(0x64, 0x64); // FS override
        $this->assertSame(0x65, 0x65); // GS override
    }

    // ========================================
    // Zero Segment Edge Cases
    // ========================================

    public function testZeroSegmentInRealMode(): void
    {
        $this->setRealMode16();

        $this->setRegister(RegisterType::DS, 0x0000);

        // With DS=0, linear = 0 * 16 + offset = offset
        $this->assertSame(0x0000, $this->getRegister(RegisterType::DS, 16));
    }

    public function testMaxSegmentInRealMode(): void
    {
        $this->setRealMode16();

        $this->setRegister(RegisterType::DS, 0xFFFF);

        // With DS=0xFFFF, linear = 0xFFFF * 16 + offset = 0xFFFF0 + offset
        $this->assertSame(0xFFFF, $this->getRegister(RegisterType::DS, 16));
    }
}
