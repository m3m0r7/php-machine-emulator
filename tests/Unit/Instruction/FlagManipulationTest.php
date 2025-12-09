<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Clc;
use PHPMachineEmulator\Instruction\Intel\x86\Stc;
use PHPMachineEmulator\Instruction\Intel\x86\Cmc;
use PHPMachineEmulator\Instruction\Intel\x86\Cld;
use PHPMachineEmulator\Instruction\Intel\x86\Std;
use PHPMachineEmulator\Instruction\Intel\x86\Cli;
use PHPMachineEmulator\Instruction\Intel\x86\Sti;
use PHPMachineEmulator\Instruction\Intel\Register;

/**
 * Tests for Flag Manipulation instructions
 *
 * CLC (0xF8) - Clear Carry Flag
 * STC (0xF9) - Set Carry Flag
 * CMC (0xF5) - Complement Carry Flag
 * CLD (0xFC) - Clear Direction Flag
 * STD (0xFD) - Set Direction Flag
 * CLI (0xFA) - Clear Interrupt Flag (IOPL sensitive in protected mode)
 * STI (0xFB) - Set Interrupt Flag (IOPL sensitive in protected mode)
 */
class FlagManipulationTest extends InstructionTestCase
{
    private Clc $clc;
    private Stc $stc;
    private Cmc $cmc;
    private Cld $cld;
    private Std $std;
    private Cli $cli;
    private Sti $sti;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->clc = new Clc($instructionList);
        $this->stc = new Stc($instructionList);
        $this->cmc = new Cmc($instructionList);
        $this->cld = new Cld($instructionList);
        $this->std = new Std($instructionList);
        $this->cli = new Cli($instructionList);
        $this->sti = new Sti($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xF8 => $this->clc,
            0xF9 => $this->stc,
            0xF5 => $this->cmc,
            0xFC => $this->cld,
            0xFD => $this->std,
            0xFA => $this->cli,
            0xFB => $this->sti,
            default => null,
        };
    }

    // ========================================
    // CLC (Clear Carry Flag) Tests - 0xF8
    // ========================================

    public function testClcClearsCarryFlag(): void
    {
        $this->setCarryFlag(true);
        $this->executeBytes([0xF8]); // CLC

        $this->assertFalse($this->getCarryFlag());
    }

    public function testClcWhenAlreadyClear(): void
    {
        $this->setCarryFlag(false);
        $this->executeBytes([0xF8]); // CLC

        $this->assertFalse($this->getCarryFlag());
    }

    public function testClcDoesNotAffectOtherFlags(): void
    {
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);
        $this->setDirectionFlag(true);
        $this->setInterruptFlag(true);

        $this->executeBytes([0xF8]); // CLC

        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->getDirectionFlag());
        $this->assertTrue($this->getInterruptFlag());
    }

    // ========================================
    // STC (Set Carry Flag) Tests - 0xF9
    // ========================================

    public function testStcSetsCarryFlag(): void
    {
        $this->setCarryFlag(false);
        $this->executeBytes([0xF9]); // STC

        $this->assertTrue($this->getCarryFlag());
    }

    public function testStcWhenAlreadySet(): void
    {
        $this->setCarryFlag(true);
        $this->executeBytes([0xF9]); // STC

        $this->assertTrue($this->getCarryFlag());
    }

    public function testStcDoesNotAffectOtherFlags(): void
    {
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->setDirectionFlag(false);
        $this->setInterruptFlag(false);

        $this->executeBytes([0xF9]); // STC

        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getDirectionFlag());
        $this->assertFalse($this->getInterruptFlag());
    }

    // ========================================
    // CMC (Complement Carry Flag) Tests - 0xF5
    // ========================================

    public function testCmcTogglesCarryFlagFromClearToSet(): void
    {
        $this->setCarryFlag(false);
        $this->executeBytes([0xF5]); // CMC

        $this->assertTrue($this->getCarryFlag());
    }

    public function testCmcTogglesCarryFlagFromSetToClear(): void
    {
        $this->setCarryFlag(true);
        $this->executeBytes([0xF5]); // CMC

        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmcDoubleToogleRestoresOriginal(): void
    {
        $this->setCarryFlag(true);

        // First CMC
        $this->executeBytes([0xF5]);
        $this->assertFalse($this->getCarryFlag());

        // Second CMC
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xF5));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->cmc->process($this->runtime, [0xF5]);
        $this->assertTrue($this->getCarryFlag());
    }

    public function testCmcDoesNotAffectOtherFlags(): void
    {
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(false);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xF5]); // CMC

        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertTrue($this->getDirectionFlag());
    }

    // ========================================
    // CLD (Clear Direction Flag) Tests - 0xFC
    // ========================================

    public function testCldClearsDirectionFlag(): void
    {
        $this->setDirectionFlag(true);
        $this->executeBytes([0xFC]); // CLD

        $this->assertFalse($this->getDirectionFlag());
    }

    public function testCldWhenAlreadyClear(): void
    {
        $this->setDirectionFlag(false);
        $this->executeBytes([0xFC]); // CLD

        $this->assertFalse($this->getDirectionFlag());
    }

    public function testCldDoesNotAffectOtherFlags(): void
    {
        $this->setDirectionFlag(true);
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->setInterruptFlag(true);

        $this->executeBytes([0xFC]); // CLD

        $this->assertFalse($this->getDirectionFlag());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getInterruptFlag());
    }

    // ========================================
    // STD (Set Direction Flag) Tests - 0xFD
    // ========================================

    public function testStdSetsDirectionFlag(): void
    {
        $this->setDirectionFlag(false);
        $this->executeBytes([0xFD]); // STD

        $this->assertTrue($this->getDirectionFlag());
    }

    public function testStdWhenAlreadySet(): void
    {
        $this->setDirectionFlag(true);
        $this->executeBytes([0xFD]); // STD

        $this->assertTrue($this->getDirectionFlag());
    }

    public function testStdDoesNotAffectOtherFlags(): void
    {
        $this->setDirectionFlag(false);
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->setInterruptFlag(false);

        $this->executeBytes([0xFD]); // STD

        $this->assertTrue($this->getDirectionFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getInterruptFlag());
    }

    // ========================================
    // CLI (Clear Interrupt Flag) Tests - 0xFA
    // Real Mode
    // ========================================

    public function testCliClearsInterruptFlagInRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(true);
        $this->executeBytes([0xFA]); // CLI

        $this->assertFalse($this->getInterruptFlag());
    }

    public function testCliWhenAlreadyClearInRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(false);
        $this->executeBytes([0xFA]); // CLI

        $this->assertFalse($this->getInterruptFlag());
    }

    public function testCliDoesNotAffectOtherFlagsInRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(true);
        $this->setCarryFlag(true);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xFA]); // CLI

        $this->assertFalse($this->getInterruptFlag());
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getDirectionFlag());
    }

    // ========================================
    // CLI (Clear Interrupt Flag) Tests - 0xFA
    // Protected Mode with privilege checks
    // ========================================

    public function testCliProtectedModeWithSufficientPrivilege(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(3); // IOPL = 3 (lowest restriction)
        $this->setCpl(3);  // CPL = 3
        $this->setInterruptFlag(true);

        $this->executeBytes([0xFA]); // CLI

        $this->assertFalse($this->getInterruptFlag());
    }

    public function testCliProtectedModeRing0(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(0);
        $this->setCpl(0);  // Ring 0 (kernel)
        $this->setInterruptFlag(true);

        $this->executeBytes([0xFA]); // CLI

        $this->assertFalse($this->getInterruptFlag());
    }

    public function testCliProtectedModeCplEqualsIopl(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(2);
        $this->setCpl(2);  // CPL == IOPL
        $this->setInterruptFlag(true);

        $this->executeBytes([0xFA]); // CLI

        $this->assertFalse($this->getInterruptFlag());
    }

    public function testCliProtectedModeInsufficientPrivilegeThrowsFault(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(0);  // IOPL = 0 (most restrictive)
        $this->setCpl(3);   // CPL = 3 (user mode)
        $this->setInterruptFlag(true);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xFA]); // CLI should throw GP fault
    }

    public function testCliProtectedModeCpl1Iopl0ThrowsFault(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(0);
        $this->setCpl(1);
        $this->setInterruptFlag(true);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xFA]); // CLI
    }

    public function testCliProtectedModeCpl2Iopl1ThrowsFault(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(1);
        $this->setCpl(2);
        $this->setInterruptFlag(true);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xFA]); // CLI
    }

    // ========================================
    // STI (Set Interrupt Flag) Tests - 0xFB
    // Real Mode
    // ========================================

    public function testStiSetsInterruptFlagInRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(false);
        $this->executeBytes([0xFB]); // STI

        $this->assertTrue($this->getInterruptFlag());
    }

    public function testStiWhenAlreadySetInRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(true);
        $this->executeBytes([0xFB]); // STI

        $this->assertTrue($this->getInterruptFlag());
    }

    public function testStiDoesNotAffectOtherFlagsInRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(false);
        $this->setCarryFlag(false);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xFB]); // STI

        $this->assertTrue($this->getInterruptFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getDirectionFlag());
    }

    // ========================================
    // STI (Set Interrupt Flag) Tests - 0xFB
    // Protected Mode with privilege checks
    // ========================================

    public function testStiProtectedModeWithSufficientPrivilege(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(3);
        $this->setCpl(3);
        $this->setInterruptFlag(false);

        $this->executeBytes([0xFB]); // STI

        $this->assertTrue($this->getInterruptFlag());
    }

    public function testStiProtectedModeRing0(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(0);
        $this->setCpl(0);  // Ring 0
        $this->setInterruptFlag(false);

        $this->executeBytes([0xFB]); // STI

        $this->assertTrue($this->getInterruptFlag());
    }

    public function testStiProtectedModeCplEqualsIopl(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(2);
        $this->setCpl(2);
        $this->setInterruptFlag(false);

        $this->executeBytes([0xFB]); // STI

        $this->assertTrue($this->getInterruptFlag());
    }

    public function testStiProtectedModeInsufficientPrivilegeThrowsFault(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(0);
        $this->setCpl(3);
        $this->setInterruptFlag(false);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xFB]); // STI should throw GP fault
    }

    public function testStiProtectedModeCpl1Iopl0ThrowsFault(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(0);
        $this->setCpl(1);
        $this->setInterruptFlag(false);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xFB]); // STI
    }

    public function testStiProtectedModeCpl3Iopl2ThrowsFault(): void
    {
        $this->setProtectedMode(true);
        $this->setIopl(2);
        $this->setCpl(3);
        $this->setInterruptFlag(false);

        $this->expectException(FaultException::class);
        $this->executeBytes([0xFB]); // STI
    }

    // ========================================
    // STI Interrupt Delivery Block Tests
    // STI defers interrupt recognition until after next instruction
    // ========================================

    public function testStiBlocksInterruptDelivery(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(false);

        $this->executeBytes([0xFB]); // STI

        // STI should have set interrupt delivery block
        $blocked = $this->cpuContext->consumeInterruptDeliveryBlock();
        $this->assertTrue($blocked);

        // Second consume should return false
        $blocked2 = $this->cpuContext->consumeInterruptDeliveryBlock();
        $this->assertFalse($blocked2);
    }

    // ========================================
    // CLI Interrupt Delivery Block Tests
    // CLI clears any pending STI deferral
    // ========================================

    public function testCliClearsInterruptDeliveryBlock(): void
    {
        $this->setProtectedMode(false);

        // First STI to set the block
        $this->setInterruptFlag(false);
        $this->executeBytes([0xFB]); // STI
        $this->assertTrue($this->getInterruptFlag());

        // CLI should clear the block
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFA));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->cli->process($this->runtime, [0xFA]);

        // Block should be cleared
        $blocked = $this->cpuContext->consumeInterruptDeliveryBlock();
        $this->assertFalse($blocked);
    }

    // ========================================
    // Sequence Tests
    // ========================================

    public function testClcStcSequence(): void
    {
        $this->setCarryFlag(true);

        // CLC
        $this->executeBytes([0xF8]);
        $this->assertFalse($this->getCarryFlag());

        // STC
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xF9));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->stc->process($this->runtime, [0xF9]);
        $this->assertTrue($this->getCarryFlag());

        // CLC again
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xF8));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->clc->process($this->runtime, [0xF8]);
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCldStdSequence(): void
    {
        $this->setDirectionFlag(true);

        // CLD
        $this->executeBytes([0xFC]);
        $this->assertFalse($this->getDirectionFlag());

        // STD
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFD));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->std->process($this->runtime, [0xFD]);
        $this->assertTrue($this->getDirectionFlag());

        // CLD again
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFC));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->cld->process($this->runtime, [0xFC]);
        $this->assertFalse($this->getDirectionFlag());
    }

    public function testCliStiSequenceRealMode(): void
    {
        $this->setProtectedMode(false);
        $this->setInterruptFlag(true);

        // CLI
        $this->executeBytes([0xFA]);
        $this->assertFalse($this->getInterruptFlag());

        // STI
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFB));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->sti->process($this->runtime, [0xFB]);
        $this->assertTrue($this->getInterruptFlag());

        // CLI again
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFA));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->cli->process($this->runtime, [0xFA]);
        $this->assertFalse($this->getInterruptFlag());
    }

    public function testMultipleCmcToggle(): void
    {
        $this->setCarryFlag(false);

        // Toggle 5 times - should end up set (odd number of toggles)
        for ($i = 0; $i < 5; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xF5));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->cmc->process($this->runtime, [0xF5]);
        }

        $this->assertTrue($this->getCarryFlag());
    }

    public function testMultipleCmcToggleEven(): void
    {
        $this->setCarryFlag(false);

        // Toggle 6 times - should end up clear (even number of toggles)
        for ($i = 0; $i < 6; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xF5));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->cmc->process($this->runtime, [0xF5]);
        }

        $this->assertFalse($this->getCarryFlag());
    }

    // ========================================
    // All Flags Independent Tests
    // ========================================

    public function testAllFlagInstructionsAreIndependent(): void
    {
        // Set all flags to known state
        $this->setCarryFlag(true);
        $this->setDirectionFlag(true);
        $this->setInterruptFlag(true);
        $this->setProtectedMode(false);

        // CLC only affects carry
        $this->executeBytes([0xF8]);
        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getDirectionFlag());
        $this->assertTrue($this->getInterruptFlag());

        // CLD only affects direction
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFC));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->cld->process($this->runtime, [0xFC]);
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getDirectionFlag());
        $this->assertTrue($this->getInterruptFlag());

        // CLI only affects interrupt
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xFA));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->cli->process($this->runtime, [0xFA]);
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getDirectionFlag());
        $this->assertFalse($this->getInterruptFlag());
    }
}
