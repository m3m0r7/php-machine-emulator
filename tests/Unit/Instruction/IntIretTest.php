<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Iret;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for INT and IRET instructions
 *
 * INT (0xCD): Software interrupt
 * IRET (0xCF): Interrupt return
 *
 * Real mode INT sequence:
 * 1. Push FLAGS
 * 2. Push CS
 * 3. Push IP
 * 4. Clear IF
 * 5. Jump to IVT[vector]
 *
 * IRET sequence:
 * 1. Pop IP
 * 2. Pop CS
 * 3. Pop FLAGS
 */
class IntIretTest extends InstructionTestCase
{
    private Int_ $int;
    private Iret $iret;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->int = new Int_($instructionList);
        $this->iret = new Iret($instructionList);

        // Default to real mode 16-bit for INT/IRET tests
        $this->setRealMode16();
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xCD => $this->int,
            0xCF => $this->iret,
            default => null,
        };
    }

    // ========================================
    // IRET Flag Restoration Tests
    // ========================================

    /**
     * Test IRET restores all flags correctly
     */
    public function testIretRestoresAllFlags(): void
    {
        // Set up stack with IP, CS, FLAGS
        // FLAGS = CF(1) | PF(4) | AF(16) | ZF(64) | SF(128) | OF(2048) | DF(1024) | IF(512)
        // Binary: 0000 1111 0101 0101 = 0x0F55
        $flags = 0x0001 | 0x0004 | 0x0010 | 0x0040 | 0x0080 | 0x0800 | 0x0400 | 0x0200;
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        // Set up stack (push order: FLAGS, CS, IP - so pop order: IP, CS, FLAGS)
        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase);

        // Write to stack in reverse order (lowest address = top of stack)
        $this->writeMemory($stackBase - 6, $returnIp, 16);  // IP at top
        $this->writeMemory($stackBase - 4, $returnCs, 16);  // CS
        $this->writeMemory($stackBase - 2, $flags, 16);     // FLAGS at bottom
        $this->setRegister(RegisterType::ESP, $stackBase - 6);

        // Clear all flags before IRET
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setParityFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);
        $this->memoryAccessor->setDirectionFlag(false);
        $this->memoryAccessor->setInterruptFlag(false);

        // Execute IRET
        $this->executeBytes([0xCF]);

        // Verify all flags are restored
        $this->assertTrue($this->getCarryFlag(), 'CF should be set');
        $this->assertTrue($this->memoryAccessor->shouldParityFlag(), 'PF should be set');
        $this->assertTrue($this->getZeroFlag(), 'ZF should be set');
        $this->assertTrue($this->getSignFlag(), 'SF should be set');
        $this->assertTrue($this->getOverflowFlag(), 'OF should be set');
        $this->assertTrue($this->getDirectionFlag(), 'DF should be set');
        $this->assertTrue($this->getInterruptFlag(), 'IF should be set');
    }

    /**
     * Test IRET clears flags when FLAGS value has them clear
     */
    public function testIretClearsFlags(): void
    {
        $flags = 0x0002; // Only reserved bit set, all testable flags clear
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase);

        $this->writeMemory($stackBase - 6, $returnIp, 16);
        $this->writeMemory($stackBase - 4, $returnCs, 16);
        $this->writeMemory($stackBase - 2, $flags, 16);
        $this->setRegister(RegisterType::ESP, $stackBase - 6);

        // Set all flags before IRET
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setParityFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(true);
        $this->memoryAccessor->setDirectionFlag(true);
        $this->memoryAccessor->setInterruptFlag(true);

        $this->executeBytes([0xCF]);

        // Verify all flags are cleared
        $this->assertFalse($this->getCarryFlag(), 'CF should be clear');
        $this->assertFalse($this->memoryAccessor->shouldParityFlag(), 'PF should be clear');
        $this->assertFalse($this->getZeroFlag(), 'ZF should be clear');
        $this->assertFalse($this->getSignFlag(), 'SF should be clear');
        $this->assertFalse($this->getOverflowFlag(), 'OF should be clear');
        $this->assertFalse($this->getDirectionFlag(), 'DF should be clear');
        $this->assertFalse($this->getInterruptFlag(), 'IF should be clear');
    }

    /**
     * Test IRET restores only CF
     */
    public function testIretRestoresOnlyCf(): void
    {
        $flags = 0x0001; // Only CF set
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase);
        $this->writeMemory($stackBase - 6, $returnIp, 16);
        $this->writeMemory($stackBase - 4, $returnCs, 16);
        $this->writeMemory($stackBase - 2, $flags, 16);
        $this->setRegister(RegisterType::ESP, $stackBase - 6);

        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0xCF]);

        $this->assertTrue($this->getCarryFlag(), 'CF should be set');
        $this->assertFalse($this->getZeroFlag(), 'ZF should be clear');
    }

    /**
     * Test IRET restores only ZF
     */
    public function testIretRestoresOnlyZf(): void
    {
        $flags = 0x0040; // Only ZF set
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase);
        $this->writeMemory($stackBase - 6, $returnIp, 16);
        $this->writeMemory($stackBase - 4, $returnCs, 16);
        $this->writeMemory($stackBase - 2, $flags, 16);
        $this->setRegister(RegisterType::ESP, $stackBase - 6);

        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setCarryFlag(true);

        $this->executeBytes([0xCF]);

        $this->assertTrue($this->getZeroFlag(), 'ZF should be set');
        $this->assertFalse($this->getCarryFlag(), 'CF should be clear');
    }

    /**
     * Test IRET restores only OF
     */
    public function testIretRestoresOnlyOf(): void
    {
        $flags = 0x0800; // Only OF set (bit 11)
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase);
        $this->writeMemory($stackBase - 6, $returnIp, 16);
        $this->writeMemory($stackBase - 4, $returnCs, 16);
        $this->writeMemory($stackBase - 2, $flags, 16);
        $this->setRegister(RegisterType::ESP, $stackBase - 6);

        $this->memoryAccessor->setOverflowFlag(false);
        $this->memoryAccessor->setSignFlag(true);

        $this->executeBytes([0xCF]);

        $this->assertTrue($this->getOverflowFlag(), 'OF should be set');
        $this->assertFalse($this->getSignFlag(), 'SF should be clear');
    }

    /**
     * Test IRET restores SF and OF independently
     */
    public function testIretRestoresSfOfIndependently(): void
    {
        // SF=1, OF=0
        $flags = 0x0080;
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase);
        $this->writeMemory($stackBase - 6, $returnIp, 16);
        $this->writeMemory($stackBase - 4, $returnCs, 16);
        $this->writeMemory($stackBase - 2, $flags, 16);
        $this->setRegister(RegisterType::ESP, $stackBase - 6);

        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(true);

        $this->executeBytes([0xCF]);

        $this->assertTrue($this->getSignFlag(), 'SF should be set');
        $this->assertFalse($this->getOverflowFlag(), 'OF should be clear');
    }

    /**
     * Test IRET updates ESP correctly in 16-bit mode
     */
    public function testIretUpdatesEsp16Bit(): void
    {
        $flags = 0x0000;
        $returnIp = 0x1234;
        $returnCs = 0x0000;

        $stackBase = 0x1000;
        $this->setRegister(RegisterType::ESP, $stackBase - 6);
        $this->writeMemory($stackBase - 6, $returnIp, 16);
        $this->writeMemory($stackBase - 4, $returnCs, 16);
        $this->writeMemory($stackBase - 2, $flags, 16);

        $this->executeBytes([0xCF]);

        // ESP should be restored to stackBase (3 x 16-bit pops = 6 bytes)
        $this->assertSame($stackBase, $this->getRegister(RegisterType::ESP) & 0xFFFF);
    }

    // ========================================
    // INT Real Mode IVT Tests
    // ========================================

    /**
     * Test INT pushes FLAGS, CS, IP to stack in real mode
     * Uses vector 0x80 which is NOT a BIOS interrupt, so vectorInterrupt is called
     */
    public function testIntPushesStateToStack(): void
    {
        // Use vector 0x80 which is not a BIOS interrupt
        $vector = 0x80;
        $handlerOffset = 0x1000;
        $handlerSegment = 0x0000;

        // Write IVT entry at vector * 4
        $ivtAddress = $vector * 4;
        $this->writeMemory($ivtAddress, $handlerOffset, 16);
        $this->writeMemory($ivtAddress + 2, $handlerSegment, 16);

        // Set up initial state
        $stackBase = 0x2000;
        $this->setRegister(RegisterType::ESP, $stackBase);
        $this->setRegister(RegisterType::CS, 0x0000);

        // Set some flags
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setInterruptFlag(true);

        // Execute INT 0x20
        // The instruction is at offset 0, INT reads vector from offset 1
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xCD) . chr($vector));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte(); // consume opcode
        $this->int->process($this->runtime, [0xCD]);

        // Verify stack contains pushed values
        // Stack grows downward, so:
        // stackBase - 2: FLAGS
        // stackBase - 4: CS
        // stackBase - 6: IP (return address)
        $pushedFlags = $this->readMemory($stackBase - 2, 16);
        $pushedCs = $this->readMemory($stackBase - 4, 16);
        $pushedIp = $this->readMemory($stackBase - 6, 16);

        // FLAGS should have CF and ZF set (0x41 = CF | ZF, plus IF was set)
        $this->assertTrue(($pushedFlags & 0x0001) !== 0, 'CF should be in pushed FLAGS');
        $this->assertTrue(($pushedFlags & 0x0040) !== 0, 'ZF should be in pushed FLAGS');

        // CS should be 0
        $this->assertSame(0, $pushedCs);

        // Return IP should be 2 (after INT xx instruction)
        $this->assertSame(2, $pushedIp);
    }

    /**
     * Test INT clears IF flag
     * Uses vector 0x80 which is NOT a BIOS interrupt
     */
    public function testIntClearsIfFlag(): void
    {
        $vector = 0x80;
        $ivtAddress = $vector * 4;
        $this->writeMemory($ivtAddress, 0x1000, 16);
        $this->writeMemory($ivtAddress + 2, 0x0000, 16);

        $stackBase = 0x2000;
        $this->setRegister(RegisterType::ESP, $stackBase);

        // Set IF before INT
        $this->memoryAccessor->setInterruptFlag(true);
        $this->assertTrue($this->getInterruptFlag());

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xCD) . chr($vector));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->int->process($this->runtime, [0xCD]);

        // IF should be cleared after INT
        $this->assertFalse($this->getInterruptFlag(), 'IF should be cleared after INT');
    }
}
