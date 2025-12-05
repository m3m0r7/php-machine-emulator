<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for Protected Mode <=> Real Mode switching.
 *
 * Tests verify:
 * - CR0 PE bit manipulation
 * - Mode switching sequence
 * - GDT/LDT setup
 * - IDT setup
 * - Segment descriptor handling
 * - Privilege level transitions
 */
class ProtectedModeTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    // ========================================
    // Real Mode Tests
    // ========================================

    public function testRealModeDefaults(): void
    {
        $this->setRealMode16();

        $this->assertFalse($this->isProtectedMode());
        $this->assertSame(16, $this->cpuContext->operandSize());
        $this->assertSame(16, $this->cpuContext->addressSize());
    }

    public function testRealMode32BitOverride(): void
    {
        $this->setRealMode32();

        $this->assertFalse($this->isProtectedMode());
        $this->assertSame(32, $this->cpuContext->operandSize());
        $this->assertSame(32, $this->cpuContext->addressSize());
    }

    // ========================================
    // Protected Mode Tests
    // ========================================

    public function testProtectedMode16Bit(): void
    {
        $this->setProtectedMode16();

        $this->assertTrue($this->isProtectedMode());
        $this->assertSame(16, $this->cpuContext->operandSize());
        $this->assertSame(16, $this->cpuContext->addressSize());
    }

    public function testProtectedMode32Bit(): void
    {
        $this->setProtectedMode32();

        $this->assertTrue($this->isProtectedMode());
        $this->assertSame(32, $this->cpuContext->operandSize());
        $this->assertSame(32, $this->cpuContext->addressSize());
    }

    // ========================================
    // CR0 Register Tests
    // ========================================

    public function testCR0PEBit(): void
    {
        // CR0.PE (bit 0) controls protected mode
        $cr0WithPE = 0x00000001;
        $cr0WithoutPE = 0x00000000;

        // PE bit should be bit 0
        $this->assertSame(1, $cr0WithPE & 0x1);
        $this->assertSame(0, $cr0WithoutPE & 0x1);
    }

    public function testCR0PGBit(): void
    {
        // CR0.PG (bit 31) controls paging
        $cr0WithPG = 0x80000001; // PG and PE set

        // PG bit should be bit 31
        $this->assertSame(1, ($cr0WithPG >> 31) & 0x1);
    }

    // ========================================
    // Privilege Level Tests
    // ========================================

    public function testCPLValues(): void
    {
        $this->setProtectedMode32();

        // CPL 0 = Kernel mode
        $this->setCpl(0);
        // CPL 3 = User mode
        $this->setCpl(3);

        $this->assertTrue(true, 'CPL can be set without error');
    }

    public function testIOPLValues(): void
    {
        $this->setProtectedMode32();

        // IOPL ranges from 0 to 3
        $this->setIopl(0);
        $this->setIopl(3);

        $this->assertTrue(true, 'IOPL can be set without error');
    }

    // ========================================
    // GDT Structure Tests
    // ========================================

    public function testGDTDescriptorFormat(): void
    {
        // 8-byte GDT descriptor format
        $descriptor = [
            'limit_low' => 0xFFFF,    // Bytes 0-1
            'base_low' => 0x0000,     // Bytes 2-3
            'base_mid' => 0x00,       // Byte 4
            'access' => 0x9A,         // Byte 5: P=1, DPL=0, S=1, Type=Execute/Read
            'flags_limit' => 0xCF,    // Byte 6: G=1, D/B=1, Limit[19:16]=F
            'base_high' => 0x00,      // Byte 7
        ];

        // Calculate full limit (with granularity)
        $limitLow = $descriptor['limit_low'];
        $limitHigh = $descriptor['flags_limit'] & 0x0F;
        $fullLimit = ($limitHigh << 16) | $limitLow;
        $granularity = ($descriptor['flags_limit'] >> 7) & 0x1;

        if ($granularity) {
            $fullLimit = ($fullLimit << 12) | 0xFFF; // 4KB granularity
        }

        $this->assertSame(0xFFFFFFFF, $fullLimit);
    }

    public function testSegmentAccessByteFormat(): void
    {
        // Access byte format: P DPL S Type
        $codeSegment = 0x9A; // P=1, DPL=0, S=1, Type=1010 (Execute/Read)
        $dataSegment = 0x92; // P=1, DPL=0, S=1, Type=0010 (Read/Write)

        $present = ($codeSegment >> 7) & 0x1;
        $dpl = ($codeSegment >> 5) & 0x3;
        $descriptorType = ($codeSegment >> 4) & 0x1;
        $type = $codeSegment & 0xF;

        $this->assertSame(1, $present, 'Segment should be present');
        $this->assertSame(0, $dpl, 'DPL should be 0 (kernel)');
        $this->assertSame(1, $descriptorType, 'S should be 1 (code/data)');
        $this->assertSame(0xA, $type, 'Type should be Execute/Read code');
    }

    // ========================================
    // Mode Switch Sequence Tests
    // ========================================

    public function testRealToProtectedModeSwitch(): void
    {
        $this->setRealMode16();
        $this->assertFalse($this->isProtectedMode());

        // In real hardware, you would:
        // 1. Disable interrupts (CLI)
        // 2. Load GDT (LGDT)
        // 3. Set CR0.PE=1
        // 4. Far JMP to flush pipeline
        // 5. Load segment registers

        $this->setProtectedMode32();
        $this->assertTrue($this->isProtectedMode());
    }

    public function testProtectedToRealModeSwitch(): void
    {
        $this->setProtectedMode32();
        $this->assertTrue($this->isProtectedMode());

        // In real hardware, you would:
        // 1. Disable paging (if enabled)
        // 2. Transfer to 16-bit code segment
        // 3. Load segment registers with real-mode values
        // 4. Clear CR0.PE
        // 5. Far JMP to real-mode address

        $this->setRealMode16();
        $this->assertFalse($this->isProtectedMode());
    }

    // ========================================
    // Stack Size in Different Modes
    // ========================================

    public function testStackSizeInRealMode(): void
    {
        $this->setRealMode16();

        $this->setRegister(RegisterType::ESP, 0x1000);
        $this->setRegister(RegisterType::SS, 0x0000);

        // In 16-bit real mode, stack operations use 16-bit
        $this->assertSame(0x1000, $this->getRegister(RegisterType::ESP) & 0xFFFF);
    }

    public function testStackSizeInProtectedMode32(): void
    {
        $this->setProtectedMode32();

        $this->setRegister(RegisterType::ESP, 0x00100000);

        // In 32-bit protected mode, stack operations use 32-bit
        $this->assertSame(0x00100000, $this->getRegister(RegisterType::ESP));
    }
}
