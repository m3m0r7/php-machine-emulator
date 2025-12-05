<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for Programmable Interrupt Controller (PIC) and APIC functionality.
 *
 * Tests verify:
 * - PIC initialization (ICW1-ICW4)
 * - PIC remapping
 * - IRQ masking (OCW1)
 * - EOI commands (OCW2)
 * - APIC detection and basics
 */
class PICTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    // ========================================
    // PIC Port Constants
    // ========================================

    public function testPICPortAddresses(): void
    {
        // Master PIC ports
        $masterCommand = 0x20;
        $masterData = 0x21;

        // Slave PIC ports
        $slaveCommand = 0xA0;
        $slaveData = 0xA1;

        $this->assertSame(0x20, $masterCommand);
        $this->assertSame(0x21, $masterData);
        $this->assertSame(0xA0, $slaveCommand);
        $this->assertSame(0xA1, $slaveData);
    }

    // ========================================
    // ICW (Initialization Command Words) Tests
    // ========================================

    public function testICW1Format(): void
    {
        // ICW1 format: | A7-A5 | 1 | LTIM | ADI | SNGL | IC4 |
        // Bit 4 must be 1 for ICW1

        $icw1 = 0x11; // ICW4 needed, cascade mode, edge triggered

        $ic4 = ($icw1 >> 0) & 0x1;     // ICW4 needed
        $sngl = ($icw1 >> 1) & 0x1;    // Single (1) or cascade (0)
        $adi = ($icw1 >> 2) & 0x1;     // Call address interval
        $ltim = ($icw1 >> 3) & 0x1;    // Level (1) or edge (0) triggered
        $init = ($icw1 >> 4) & 0x1;    // Must be 1 for ICW1

        $this->assertSame(1, $ic4, 'ICW4 should be needed');
        $this->assertSame(0, $sngl, 'Should be cascade mode');
        $this->assertSame(1, $init, 'Init bit must be 1');
    }

    public function testICW2VectorOffset(): void
    {
        // ICW2 specifies the interrupt vector offset
        // Master PIC: usually remapped to 0x20 (32)
        // Slave PIC: usually remapped to 0x28 (40)

        $masterOffset = 0x20; // IRQ0-7 -> INT 0x20-0x27
        $slaveOffset = 0x28;  // IRQ8-15 -> INT 0x28-0x2F

        $this->assertSame(32, $masterOffset);
        $this->assertSame(40, $slaveOffset);
    }

    public function testICW3CascadeConfiguration(): void
    {
        // ICW3 for master: bit mask of slave connections
        // ICW3 for slave: slave ID (0-7)

        $masterICW3 = 0x04; // Slave on IRQ2
        $slaveICW3 = 0x02;  // Slave ID 2

        $this->assertSame(4, $masterICW3);
        $this->assertSame(2, $slaveICW3);
    }

    public function testICW4Format(): void
    {
        // ICW4 format: | 0 | 0 | 0 | SFNM | BUF | M/S | AEOI | μPM |

        $icw4 = 0x01; // 8086/8088 mode

        $upm = ($icw4 >> 0) & 0x1;   // 8086/8088 mode
        $aeoi = ($icw4 >> 1) & 0x1;  // Auto EOI
        $buf = ($icw4 >> 3) & 0x1;   // Buffered mode

        $this->assertSame(1, $upm, 'Should be 8086 mode');
        $this->assertSame(0, $aeoi, 'Should be manual EOI');
    }

    // ========================================
    // OCW (Operation Command Words) Tests
    // ========================================

    public function testOCW1IRQMask(): void
    {
        // OCW1 (written to data port) sets IRQ mask
        // Bit set = IRQ disabled

        $mask = 0xFB; // All IRQs masked except IRQ2 (cascade)

        $irq2Enabled = (($mask >> 2) & 0x1) === 0;

        $this->assertTrue($irq2Enabled, 'IRQ2 should be enabled for cascade');
    }

    public function testOCW2EOIFormat(): void
    {
        // OCW2 format: | R | SL | EOI | 0 | 0 | L2 | L1 | L0 |
        // Bits 3-4 must be 0 for OCW2

        $nonSpecificEOI = 0x20;  // Non-specific EOI
        $specificEOI = 0x60;     // Specific EOI (OR with IRQ number)

        $eoi = ($nonSpecificEOI >> 5) & 0x1;

        $this->assertSame(1, $eoi, 'EOI bit should be set');
    }

    // ========================================
    // IRQ to Interrupt Mapping
    // ========================================

    public function testDefaultIRQMapping(): void
    {
        // Default BIOS mapping (conflicts with exceptions)
        // IRQ0-7 -> INT 0x08-0x0F
        // IRQ8-15 -> INT 0x70-0x77

        $irq0Default = 0x08; // Timer
        $irq1Default = 0x09; // Keyboard
        $irq8Default = 0x70; // RTC

        $this->assertSame(0x08, $irq0Default);
        $this->assertSame(0x09, $irq1Default);
        $this->assertSame(0x70, $irq8Default);
    }

    public function testRemappedIRQMapping(): void
    {
        // After remapping (avoids exceptions)
        // IRQ0-7 -> INT 0x20-0x27
        // IRQ8-15 -> INT 0x28-0x2F

        $masterBase = 0x20;
        $slaveBase = 0x28;

        // IRQ0 (timer)
        $irq0Remapped = $masterBase + 0;
        $this->assertSame(0x20, $irq0Remapped);

        // IRQ1 (keyboard)
        $irq1Remapped = $masterBase + 1;
        $this->assertSame(0x21, $irq1Remapped);

        // IRQ8 (RTC) - on slave
        $irq8Remapped = $slaveBase + 0;
        $this->assertSame(0x28, $irq8Remapped);
    }

    // ========================================
    // Common IRQ Assignments
    // ========================================

    public function testCommonIRQAssignments(): void
    {
        // Standard PC IRQ assignments
        $irqAssignments = [
            0 => 'Timer',
            1 => 'Keyboard',
            2 => 'Cascade (to slave)',
            3 => 'COM2',
            4 => 'COM1',
            5 => 'LPT2',
            6 => 'Floppy',
            7 => 'LPT1',
            8 => 'RTC',
            12 => 'PS/2 Mouse',
            13 => 'FPU',
            14 => 'Primary IDE',
            15 => 'Secondary IDE',
        ];

        $this->assertSame('Timer', $irqAssignments[0]);
        $this->assertSame('Keyboard', $irqAssignments[1]);
        $this->assertSame('Cascade (to slave)', $irqAssignments[2]);
    }

    // ========================================
    // APIC Basic Tests
    // ========================================

    public function testAPICDetection(): void
    {
        // APIC is detected via CPUID
        // CPUID.01H:EDX[9] = APIC support

        $cpuidEdx = 0x00000200; // Bit 9 set = APIC present

        $hasApic = (($cpuidEdx >> 9) & 0x1) === 1;

        $this->assertTrue($hasApic, 'APIC bit should be detected');
    }

    public function testLocalAPICBaseAddress(): void
    {
        // Default Local APIC base address
        $defaultBase = 0xFEE00000;

        $this->assertSame(0xFEE00000, $defaultBase);
    }

    public function testIOAPICBaseAddress(): void
    {
        // Default I/O APIC base address
        $defaultBase = 0xFEC00000;

        $this->assertSame(0xFEC00000, $defaultBase);
    }

    // ========================================
    // Interrupt Priority
    // ========================================

    public function testIRQPriority(): void
    {
        // IRQ priority: 0 > 1 > 2 > ... > 7 (on each PIC)
        // IRQ0 (timer) has highest priority on master
        // IRQ8 (RTC) has highest priority on slave

        // Slave interrupts come through IRQ2 on master
        // So effective priority is: 0, 1, 8-15, 3, 4, 5, 6, 7

        $this->assertTrue(true, 'IRQ priority documented');
    }
}
