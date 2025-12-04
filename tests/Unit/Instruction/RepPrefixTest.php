<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\RepPrefix;
use PHPMachineEmulator\Instruction\Intel\x86\Stosb;
use PHPMachineEmulator\Instruction\Intel\x86\Stosw;
use PHPMachineEmulator\Instruction\Intel\x86\Movsb;
use PHPMachineEmulator\Instruction\Intel\x86\Movsw;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsw;
use PHPMachineEmulator\Instruction\Intel\x86\Scasb;
use PHPMachineEmulator\Instruction\Intel\x86\Scasw;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsb;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsw;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\ExecutionStatus;

/**
 * Tests for REP/REPE/REPNE prefix instructions
 *
 * REP (0xF3) - Repeat while CX/ECX != 0
 * REPE/REPZ (0xF3) - Repeat while equal/zero (for CMPS/SCAS)
 * REPNE/REPNZ (0xF2) - Repeat while not equal/not zero (for CMPS/SCAS)
 *
 * String instructions:
 * - MOVSB (0xA4), MOVSW/MOVSD (0xA5)
 * - STOSB (0xAA), STOSW/STOSD (0xAB)
 * - LODSB (0xAC), LODSW/LODSD (0xAD)
 * - SCASB (0xAE), SCASW/SCASD (0xAF)
 * - CMPSB (0xA6), CMPSW/CMPSD (0xA7)
 *
 * Prefix combinations tested:
 * - REP + string instruction
 * - REP + 0x66 (operand size) + string instruction
 * - REP + 0x67 (address size) + string instruction
 * - REP + 0x66 + 0x67 + string instruction
 */
class RepPrefixTest extends InstructionTestCase
{
    private RepPrefix $repPrefix;
    private Stosb $stosb;
    private Stosw $stosw;
    private Movsb $movsb;
    private Movsw $movsw;
    private Scasb $scasb;
    private Scasw $scasw;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        // Create string instruction instances
        $this->stosb = new Stosb($instructionList);
        $this->stosw = new Stosw($instructionList);
        $this->movsb = new Movsb($instructionList);
        $this->movsw = new Movsw($instructionList);
        $this->scasb = new Scasb($instructionList);
        $this->scasw = new Scasw($instructionList);

        // Mock findInstruction to return the correct instruction
        $instructionList->method('findInstruction')->willReturnCallback(function ($opcodes) {
            $opcode = is_array($opcodes) ? $opcodes[0] : $opcodes;
            return match ($opcode) {
                0xAA => [$this->stosb, 0xAA],
                0xAB => [$this->stosw, 0xAB],
                0xA4 => [$this->movsb, 0xA4],
                0xA5 => [$this->movsw, 0xA5],
                0xAE => [$this->scasb, 0xAE],
                0xAF => [$this->scasw, 0xAF],
                default => [null, $opcode],
            };
        });

        $this->repPrefix = new RepPrefix($instructionList);

        // Default: clear direction flag, set segments
        $this->setDirectionFlag(false);
        $this->setRegister(RegisterType::DS, 0x0000);
        $this->setRegister(RegisterType::ES, 0x0000);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xF3, 0xF2 => $this->repPrefix,
            default => null,
        };
    }

    // ========================================
    // Basic REP STOSB Tests
    // ========================================

    public function testRepStosb_ZeroCount(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 0);
        $this->setRegister(RegisterType::EAX, 0xAA);
        $this->setRegister(RegisterType::EDI, 0x2000);

        // Pre-fill memory
        $this->writeMemory(0x2000, 0xFF, 8);

        // Execute REP STOSB (F3 AA)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAA)); // STOSB opcode after REP
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Memory should NOT be changed when ECX=0
        $this->assertSame(0xFF, $this->readMemory(0x2000, 8));
        // EDI should NOT change
        $this->assertSame(0x2000, $this->getRegister(RegisterType::EDI));
    }

    public function testRepStosb_SingleIteration(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 1);
        $this->setRegister(RegisterType::EAX, 0x55);
        $this->setRegister(RegisterType::EDI, 0x2000);

        // Pre-fill memory
        $this->writeMemory(0x2000, 0xFF, 8);

        // Execute REP STOSB (F3 AA)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAA));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0x55, $this->readMemory(0x2000, 8));
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    public function testRepStosb_MultipleIterations(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 4);
        $this->setRegister(RegisterType::EAX, 0x00);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Pre-fill memory with garbage
        $this->writeMemory(0x2000, 0xDE, 8);
        $this->writeMemory(0x2001, 0xAD, 8);
        $this->writeMemory(0x2002, 0xBE, 8);
        $this->writeMemory(0x2003, 0xEF, 8);

        // Execute REP STOSB (F3 AA)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAA));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // All 4 bytes should be zeroed
        $this->assertSame(0x00, $this->readMemory(0x2000, 8));
        $this->assertSame(0x00, $this->readMemory(0x2001, 8));
        $this->assertSame(0x00, $this->readMemory(0x2002, 8));
        $this->assertSame(0x00, $this->readMemory(0x2003, 8));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    // ========================================
    // REP + Operand Size Prefix (0x66) Tests
    // ========================================

    public function testRepStosd_With66Prefix_16bitDefault(): void
    {
        // In 16-bit mode, 0x66 prefix toggles to 32-bit operand
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 2);
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Pre-fill memory
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory(0x2000 + $i, 0xFF, 8);
        }

        // Execute REP 66 STOSD (F3 66 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Should store two 32-bit values (0xDEADBEEF)
        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2000, 32));
        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2004, 32));
        $this->assertSame(0x2008, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    public function testRepStosd_With66Prefix_ZeroFill(): void
    {
        // Critical test: REP STOSD with 0x66 prefix should zero memory
        // This was the original bug - 0x66 was being treated as the instruction opcode
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 4);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::EDI, 0x3D80);
        $this->setDirectionFlag(false);

        // Pre-fill with test pattern (like in the ISOLINUX crash scenario)
        $this->writeMemory(0x3D80, 0x11111111, 32);
        $this->writeMemory(0x3D84, 0xDEADBEEF, 32);
        $this->writeMemory(0x3D88, 0x33333333, 32);
        $this->writeMemory(0x3D8C, 0x44444444, 32);

        // Execute REP 66 STOSD (F3 66 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // ALL 4 dwords should be zeroed - this was the bug
        $this->assertSame(0x00000000, $this->readMemory(0x3D80, 32), '0x3D80 should be zeroed');
        $this->assertSame(0x00000000, $this->readMemory(0x3D84, 32), '0x3D84 should be zeroed');
        $this->assertSame(0x00000000, $this->readMemory(0x3D88, 32), '0x3D88 should be zeroed');
        $this->assertSame(0x00000000, $this->readMemory(0x3D8C, 32), '0x3D8C should be zeroed');
        $this->assertSame(0x3D90, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    public function testRepStosw_Without66Prefix_16bitDefault(): void
    {
        // In 16-bit mode without prefix, STOSW stores 16-bit values
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 3);
        $this->setRegister(RegisterType::EAX, 0x1234);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Execute REP STOSW (F3 AB) without 0x66 prefix
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0x1234, $this->readMemory(0x2000, 16));
        $this->assertSame(0x1234, $this->readMemory(0x2002, 16));
        $this->assertSame(0x1234, $this->readMemory(0x2004, 16));
        $this->assertSame(0x2006, $this->getRegister(RegisterType::EDI));
    }

    public function testRepStosd_With66Prefix_32bitDefault(): void
    {
        // In 32-bit mode, 0x66 prefix toggles to 16-bit operand
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::ECX, 2);
        $this->setRegister(RegisterType::EAX, 0xABCD1234);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Execute REP 66 STOSW (F3 66 AB) - 0x66 in 32-bit mode = STOSW
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Should store 16-bit values (0x1234 from low word of EAX)
        $this->assertSame(0x1234, $this->readMemory(0x2000, 16));
        $this->assertSame(0x1234, $this->readMemory(0x2002, 16));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // REP + Address Size Prefix (0x67) Tests
    // ========================================

    public function testRepStosb_With67Prefix_UsesEcx(): void
    {
        // 0x67 prefix toggles address size, affecting which counter register is used
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 3);
        $this->setRegister(RegisterType::EAX, 0x77);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Execute REP 67 STOSB (F3 67 AA)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x67) . chr(0xAA));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0x77, $this->readMemory(0x2000, 8));
        $this->assertSame(0x77, $this->readMemory(0x2001, 8));
        $this->assertSame(0x77, $this->readMemory(0x2002, 8));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    // ========================================
    // REP + Both Prefixes (0x66 + 0x67) Tests
    // ========================================

    public function testRepStosd_WithBothPrefixes_66_67(): void
    {
        // Test: F3 66 67 AB (REP + operand size + address size + STOSD)
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 2);
        $this->setRegister(RegisterType::EAX, 0xCAFEBABE);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Pre-fill
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory(0x2000 + $i, 0xFF, 8);
        }

        // Execute REP 66 67 STOSD (F3 66 67 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0x67) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0xCAFEBABE, $this->readMemory(0x2000, 32));
        $this->assertSame(0xCAFEBABE, $this->readMemory(0x2004, 32));
        $this->assertSame(0x2008, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    public function testRepStosd_WithBothPrefixes_67_66(): void
    {
        // Test: F3 67 66 AB (REP + address size + operand size + STOSD)
        // Prefix order should not matter
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 2);
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Execute REP 67 66 STOSD (F3 67 66 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x67) . chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0x12345678, $this->readMemory(0x2000, 32));
        $this->assertSame(0x12345678, $this->readMemory(0x2004, 32));
    }

    // ========================================
    // REP MOVSB/MOVSD Tests
    // ========================================

    public function testRepMovsb_CopyMemory(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 4);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Source data
        $this->writeMemory(0x1000, 0x48, 8); // 'H'
        $this->writeMemory(0x1001, 0x65, 8); // 'e'
        $this->writeMemory(0x1002, 0x6C, 8); // 'l'
        $this->writeMemory(0x1003, 0x6C, 8); // 'l'

        // Execute REP MOVSB (F3 A4)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xA4));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0x48, $this->readMemory(0x2000, 8));
        $this->assertSame(0x65, $this->readMemory(0x2001, 8));
        $this->assertSame(0x6C, $this->readMemory(0x2002, 8));
        $this->assertSame(0x6C, $this->readMemory(0x2003, 8));
        $this->assertSame(0x1004, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }

    public function testRepMovsd_With66Prefix(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 2);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Source data
        $this->writeMemory(0x1000, 0xDEADBEEF, 32);
        $this->writeMemory(0x1004, 0xCAFEBABE, 32);

        // Execute REP 66 MOVSD (F3 66 A5)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xA5));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2000, 32));
        $this->assertSame(0xCAFEBABE, $this->readMemory(0x2004, 32));
        $this->assertSame(0x1008, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2008, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // REPNE/REPNZ (0xF2) Tests - SCAS
    // ========================================

    public function testRepneScasb_FindByte(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 10);
        $this->setRegister(RegisterType::EAX, 0x00); // Looking for null terminator
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // String "Hello\0"
        $this->writeMemory(0x2000, 0x48, 8); // 'H'
        $this->writeMemory(0x2001, 0x65, 8); // 'e'
        $this->writeMemory(0x2002, 0x6C, 8); // 'l'
        $this->writeMemory(0x2003, 0x6C, 8); // 'l'
        $this->writeMemory(0x2004, 0x6F, 8); // 'o'
        $this->writeMemory(0x2005, 0x00, 8); // '\0'

        // Execute REPNE SCASB (F2 AE)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAE));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF2);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Should stop after finding null at position 6
        $this->assertSame(0x2006, $this->getRegister(RegisterType::EDI));
        $this->assertSame(4, $this->getRegister(RegisterType::ECX)); // 10 - 6 = 4
        $this->assertTrue($this->getZeroFlag()); // Found match
    }

    public function testRepneScasb_NotFound(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 4);
        $this->setRegister(RegisterType::EAX, 0xFF); // Looking for 0xFF
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // No 0xFF in this data
        $this->writeMemory(0x2000, 0x01, 8);
        $this->writeMemory(0x2001, 0x02, 8);
        $this->writeMemory(0x2002, 0x03, 8);
        $this->writeMemory(0x2003, 0x04, 8);

        // Execute REPNE SCASB (F2 AE)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAE));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF2);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Should exhaust ECX without finding
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
        $this->assertFalse($this->getZeroFlag()); // No match found
    }

    // ========================================
    // REPE/REPZ (0xF3) Tests - SCAS
    // ========================================

    public function testRepeScasb_FindNonMatching(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 10);
        $this->setRegister(RegisterType::EAX, 0x41); // Looking while equal to 'A'
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // "AAAB"
        $this->writeMemory(0x2000, 0x41, 8); // 'A'
        $this->writeMemory(0x2001, 0x41, 8); // 'A'
        $this->writeMemory(0x2002, 0x41, 8); // 'A'
        $this->writeMemory(0x2003, 0x42, 8); // 'B'

        // Execute REPE SCASB (F3 AE)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAE));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Should stop when finding 'B' at position 4
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
        $this->assertSame(6, $this->getRegister(RegisterType::ECX)); // 10 - 4 = 6
        $this->assertFalse($this->getZeroFlag()); // Found non-match
    }

    // ========================================
    // Direction Flag Tests
    // ========================================

    public function testRepStosb_BackwardDirection(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 4);
        $this->setRegister(RegisterType::EAX, 0xAA);
        $this->setRegister(RegisterType::EDI, 0x2003); // Start at end
        $this->setDirectionFlag(true); // Backward

        // Execute REP STOSB (F3 AA)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAA));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Memory should be filled backwards
        $this->assertSame(0xAA, $this->readMemory(0x2003, 8));
        $this->assertSame(0xAA, $this->readMemory(0x2002, 8));
        $this->assertSame(0xAA, $this->readMemory(0x2001, 8));
        $this->assertSame(0xAA, $this->readMemory(0x2000, 8));
        // EDI should decrement
        $this->assertSame(0x1FFF, $this->getRegister(RegisterType::EDI));
    }

    public function testRepStosd_With66Prefix_BackwardDirection(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 2);
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);
        $this->setRegister(RegisterType::EDI, 0x2004); // Start at offset
        $this->setDirectionFlag(true); // Backward

        // Execute REP 66 STOSD (F3 66 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Memory should be filled backwards
        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2004, 32));
        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2000, 32));
        // EDI should point before start
        $this->assertSame(0x1FFC, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // Protected Mode Tests
    // ========================================

    public function testRepStosd_ProtectedMode32(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::ECX, 3);
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Execute REP STOSD (F3 AB) - 32-bit default
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0x12345678, $this->readMemory(0x2000, 32));
        $this->assertSame(0x12345678, $this->readMemory(0x2004, 32));
        $this->assertSame(0x12345678, $this->readMemory(0x2008, 32));
        $this->assertSame(0x200C, $this->getRegister(RegisterType::EDI));
    }

    public function testRepStosw_ProtectedMode32_With66Prefix(): void
    {
        // In 32-bit protected mode, 0x66 toggles to 16-bit
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::ECX, 4);
        $this->setRegister(RegisterType::EAX, 0xABCD);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Execute REP 66 STOSW (F3 66 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        $this->assertSame(0xABCD, $this->readMemory(0x2000, 16));
        $this->assertSame(0xABCD, $this->readMemory(0x2002, 16));
        $this->assertSame(0xABCD, $this->readMemory(0x2004, 16));
        $this->assertSame(0xABCD, $this->readMemory(0x2006, 16));
        $this->assertSame(0x2008, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // Large Count Tests
    // ========================================

    public function testRepStosd_LargeCount(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::ECX, 256); // 1KB when using STOSD
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::EDI, 0x1000);
        $this->setDirectionFlag(false);

        // Pre-fill with pattern
        for ($i = 0; $i < 256; $i++) {
            $this->writeMemory(0x1000 + ($i * 4), 0xDEADBEEF, 32);
        }

        // Execute REP 66 STOSD (F3 66 AB)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x66) . chr(0xAB));
        $this->memoryStream->setOffset(0);

        $result = $this->repPrefix->process($this->runtime, 0xF3);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);

        // Verify all memory is zeroed
        for ($i = 0; $i < 256; $i++) {
            $addr = 0x1000 + ($i * 4);
            $this->assertSame(0x00000000, $this->readMemory($addr, 32), "Address 0x" . dechex($addr) . " should be zeroed");
        }

        $this->assertSame(0x1400, $this->getRegister(RegisterType::EDI)); // 0x1000 + (256 * 4)
        $this->assertSame(0, $this->getRegister(RegisterType::ECX));
    }
}
