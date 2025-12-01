<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Movsb;
use PHPMachineEmulator\Instruction\Intel\x86\Movsw;
use PHPMachineEmulator\Instruction\Intel\x86\Stosb;
use PHPMachineEmulator\Instruction\Intel\x86\Stosw;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsw;
use PHPMachineEmulator\Instruction\Intel\x86\Scasb;
use PHPMachineEmulator\Instruction\Intel\x86\Scasw;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsb;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsw;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for String Operation instructions
 *
 * MOVSB (0xA4) - Move byte from DS:SI to ES:DI
 * MOVSW/MOVSD (0xA5) - Move word/dword from DS:SI to ES:DI
 * STOSB (0xAA) - Store AL to ES:DI
 * STOSW/STOSD (0xAB) - Store AX/EAX to ES:DI
 * LODSB (0xAC) - Load byte from DS:SI to AL
 * LODSW/LODSD (0xAD) - Load word/dword from DS:SI to AX/EAX
 * SCASB (0xAE) - Compare AL with byte at ES:DI
 * SCASW/SCASD (0xAF) - Compare AX/EAX with word/dword at ES:DI
 * CMPSB (0xA6) - Compare byte at DS:SI with ES:DI
 * CMPSW/CMPSD (0xA7) - Compare word/dword at DS:SI with ES:DI
 *
 * Direction flag (DF): 0 = increment SI/DI, 1 = decrement SI/DI
 */
class StringOpsTest extends InstructionTestCase
{
    private Movsb $movsb;
    private Movsw $movsw;
    private Stosb $stosb;
    private Stosw $stosw;
    private Lodsb $lodsb;
    private Lodsw $lodsw;
    private Scasb $scasb;
    private Scasw $scasw;
    private Cmpsb $cmpsb;
    private Cmpsw $cmpsw;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->movsb = new Movsb($instructionList);
        $this->movsw = new Movsw($instructionList);
        $this->stosb = new Stosb($instructionList);
        $this->stosw = new Stosw($instructionList);
        $this->lodsb = new Lodsb($instructionList);
        $this->lodsw = new Lodsw($instructionList);
        $this->scasb = new Scasb($instructionList);
        $this->scasw = new Scasw($instructionList);
        $this->cmpsb = new Cmpsb($instructionList);
        $this->cmpsw = new Cmpsw($instructionList);

        // Default: clear direction flag (forward direction)
        $this->setDirectionFlag(false);
        // Initialize segment registers for real mode
        $this->setRegister(RegisterType::DS, 0x0000);
        $this->setRegister(RegisterType::ES, 0x0000);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xA4 => $this->movsb,
            0xA5 => $this->movsw,
            0xAA => $this->stosb,
            0xAB => $this->stosw,
            0xAC => $this->lodsb,
            0xAD => $this->lodsw,
            0xAE => $this->scasb,
            0xAF => $this->scasw,
            0xA6 => $this->cmpsb,
            0xA7 => $this->cmpsw,
            default => null,
        };
    }

    // ========================================
    // MOVSB Tests (0xA4)
    // ========================================

    public function testMovsbForward(): void
    {
        // Set up source data at DS:SI
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xAB, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA4]); // MOVSB

        // Data should be copied to ES:DI
        $this->assertSame(0xAB, $this->readMemory(0x2000, 8));
        // SI and DI should be incremented by 1
        $this->assertSame(0x1001, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));
    }

    public function testMovsbBackward(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1010);
        $this->setRegister(RegisterType::EDI, 0x2010);
        $this->writeMemory(0x1010, 0xCD, 8);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xA4]); // MOVSB

        $this->assertSame(0xCD, $this->readMemory(0x2010, 8));
        // SI and DI should be decremented by 1
        $this->assertSame(0x100F, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x200F, $this->getRegister(RegisterType::EDI));
    }

    public function testMovsbMultipleBytes(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0x48, 8); // 'H'
        $this->writeMemory(0x1001, 0x65, 8); // 'e'
        $this->writeMemory(0x1002, 0x6C, 8); // 'l'
        $this->setDirectionFlag(false);

        // Execute MOVSB 3 times
        for ($i = 0; $i < 3; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xA4));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->movsb->process($this->runtime, 0xA4);
        }

        $this->assertSame(0x48, $this->readMemory(0x2000, 8));
        $this->assertSame(0x65, $this->readMemory(0x2001, 8));
        $this->assertSame(0x6C, $this->readMemory(0x2002, 8));
        $this->assertSame(0x1003, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2003, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // MOVSW/MOVSD Tests (0xA5)
    // ========================================

    public function testMovsw16bitForward(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0x1234, 16);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA5]); // MOVSW

        $this->assertSame(0x1234, $this->readMemory(0x2000, 16));
        // SI and DI should be incremented by 2
        $this->assertSame(0x1002, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2002, $this->getRegister(RegisterType::EDI));
    }

    public function testMovsd32bitForward(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0x12345678, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA5]); // MOVSD

        $this->assertSame(0x12345678, $this->readMemory(0x2000, 32));
        // SI and DI should be incremented by 4
        $this->assertSame(0x1004, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
    }

    public function testMovsd32bitBackward(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::ESI, 0x1010);
        $this->setRegister(RegisterType::EDI, 0x2010);
        $this->writeMemory(0x1010, 0xDEADBEEF, 32);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xA5]); // MOVSD

        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2010, 32));
        // SI and DI should be decremented by 4
        $this->assertSame(0x100C, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x200C, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // STOSB Tests (0xAA)
    // ========================================

    public function testStosbForward(): void
    {
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAA]); // STOSB

        $this->assertSame(0xAB, $this->readMemory(0x2000, 8));
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));
    }

    public function testStosbBackward(): void
    {
        $this->setRegister(RegisterType::EAX, 0x000000CD);
        $this->setRegister(RegisterType::EDI, 0x2010);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xAA]); // STOSB

        $this->assertSame(0xCD, $this->readMemory(0x2010, 8));
        $this->assertSame(0x200F, $this->getRegister(RegisterType::EDI));
    }

    public function testStosbFillMemory(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000); // Fill with zeros
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        // Pre-fill with garbage
        $this->writeMemory(0x2000, 0xFF, 8);
        $this->writeMemory(0x2001, 0xFF, 8);
        $this->writeMemory(0x2002, 0xFF, 8);

        // Execute STOSB 3 times
        for ($i = 0; $i < 3; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xAA));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->stosb->process($this->runtime, 0xAA);
        }

        $this->assertSame(0x00, $this->readMemory(0x2000, 8));
        $this->assertSame(0x00, $this->readMemory(0x2001, 8));
        $this->assertSame(0x00, $this->readMemory(0x2002, 8));
        $this->assertSame(0x2003, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // STOSW/STOSD Tests (0xAB)
    // ========================================

    public function testStosw16bit(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::EAX, 0x00001234);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAB]); // STOSW

        $this->assertSame(0x1234, $this->readMemory(0x2000, 16));
        $this->assertSame(0x2002, $this->getRegister(RegisterType::EDI));
    }

    public function testStosd32bit(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAB]); // STOSD

        $this->assertSame(0xDEADBEEF, $this->readMemory(0x2000, 32));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
    }

    public function testStosd32bitBackward(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::EAX, 0xCAFEBABE);
        $this->setRegister(RegisterType::EDI, 0x2010);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xAB]); // STOSD

        $this->assertSame(0xCAFEBABE, $this->readMemory(0x2010, 32));
        $this->assertSame(0x200C, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // LODSB Tests (0xAC)
    // ========================================

    public function testLodsbForward(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->writeMemory(0x1000, 0xAB, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAC]); // LODSB

        $this->assertSame(0xAB, $this->getRegister(RegisterType::EAX) & 0xFF);
        $this->assertSame(0x1001, $this->getRegister(RegisterType::ESI));
    }

    public function testLodsbBackward(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1010);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->writeMemory(0x1010, 0xCD, 8);
        $this->setDirectionFlag(true);

        $this->executeBytes([0xAC]); // LODSB

        $this->assertSame(0xCD, $this->getRegister(RegisterType::EAX) & 0xFF);
        $this->assertSame(0x100F, $this->getRegister(RegisterType::ESI));
    }

    public function testLodsbPreservesHighBytes(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EAX, 0x12345600);
        $this->writeMemory(0x1000, 0x78, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAC]); // LODSB

        // High bytes should be preserved
        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testLodsbReadString(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        // Write "Hi" to memory
        $this->writeMemory(0x1000, 0x48, 8); // 'H'
        $this->writeMemory(0x1001, 0x69, 8); // 'i'
        $this->setDirectionFlag(false);

        $result = '';
        for ($i = 0; $i < 2; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xAC));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->lodsb->process($this->runtime, 0xAC);
            $result .= chr($this->getRegister(RegisterType::EAX) & 0xFF);
        }

        $this->assertSame('Hi', $result);
    }

    // ========================================
    // LODSW/LODSD Tests (0xAD)
    // ========================================

    public function testLodsw16bit(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->writeMemory(0x1000, 0x1234, 16);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAD]); // LODSW

        $this->assertSame(0x1234, $this->getRegister(RegisterType::EAX, 16));
        $this->assertSame(0x1002, $this->getRegister(RegisterType::ESI));
    }

    public function testLodsd32bit(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->writeMemory(0x1000, 0xDEADBEEF, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAD]); // LODSD

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x1004, $this->getRegister(RegisterType::ESI));
    }

    // ========================================
    // SCASB Tests (0xAE)
    // ========================================

    public function testScasbEqual(): void
    {
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0xAB, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAE]); // SCASB

        // AL == [ES:DI], so ZF should be set
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));
    }

    public function testScasbNotEqual(): void
    {
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0xCD, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAE]); // SCASB

        // AL != [ES:DI], so ZF should be clear
        $this->assertFalse($this->getZeroFlag());
        // AL (0xAB) < [ES:DI] (0xCD), so CF should be set
        $this->assertTrue($this->getCarryFlag());
    }

    public function testScasbAlGreater(): void
    {
        $this->setRegister(RegisterType::EAX, 0x000000FF);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0x10, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAE]); // SCASB

        // AL > [ES:DI], so CF should be clear
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
    }

    public function testScasbFindByteInMemory(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000); // Looking for null terminator
        $this->setRegister(RegisterType::EDI, 0x2000);
        // Write "Hi\0"
        $this->writeMemory(0x2000, 0x48, 8);
        $this->writeMemory(0x2001, 0x69, 8);
        $this->writeMemory(0x2002, 0x00, 8);
        $this->setDirectionFlag(false);

        $count = 0;
        do {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xAE));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->scasb->process($this->runtime, 0xAE);
            $count++;
        } while (!$this->getZeroFlag() && $count < 10);

        // Should find null at position 3 (after 'H', 'i', '\0')
        $this->assertSame(3, $count);
        $this->assertTrue($this->getZeroFlag());
    }

    // ========================================
    // SCASW/SCASD Tests (0xAF)
    // ========================================

    public function testScasw16bitEqual(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::EAX, 0x00001234);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0x1234, 16);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAF]); // SCASW

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x2002, $this->getRegister(RegisterType::EDI));
    }

    public function testScasd32bitEqual(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0xDEADBEEF, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAF]); // SCASD

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
    }

    public function testScasd32bitNotEqual(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0xFFFFFFFF, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAF]); // SCASD

        $this->assertFalse($this->getZeroFlag());
        // 0x00000001 < 0xFFFFFFFF, so CF should be set
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // CMPSB Tests (0xA6)
    // ========================================

    public function testCmpsbEqual(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xAB, 8);
        $this->writeMemory(0x2000, 0xAB, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA6]); // CMPSB

        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertSame(0x1001, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));
    }

    public function testCmpsbNotEqual(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xAB, 8);
        $this->writeMemory(0x2000, 0xCD, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA6]); // CMPSB

        $this->assertFalse($this->getZeroFlag());
        // 0xAB < 0xCD, so CF should be set
        $this->assertTrue($this->getCarryFlag());
    }

    public function testCmpsbSourceGreater(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xFF, 8);
        $this->writeMemory(0x2000, 0x00, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA6]); // CMPSB

        $this->assertFalse($this->getZeroFlag());
        // 0xFF > 0x00, so CF should be clear
        $this->assertFalse($this->getCarryFlag());
    }

    public function testCmpsbCompareStrings(): void
    {
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        // Write "AB" to both locations
        $this->writeMemory(0x1000, 0x41, 8); // 'A'
        $this->writeMemory(0x1001, 0x42, 8); // 'B'
        $this->writeMemory(0x2000, 0x41, 8); // 'A'
        $this->writeMemory(0x2001, 0x42, 8); // 'B'
        $this->setDirectionFlag(false);

        $allEqual = true;
        for ($i = 0; $i < 2; $i++) {
            $this->memoryStream->setOffset(0);
            $this->memoryStream->write(chr(0xA6));
            $this->memoryStream->setOffset(0);
            $this->memoryStream->byte();
            $this->cmpsb->process($this->runtime, 0xA6);
            if (!$this->getZeroFlag()) {
                $allEqual = false;
                break;
            }
        }

        $this->assertTrue($allEqual);
    }

    // ========================================
    // CMPSW/CMPSD Tests (0xA7)
    // ========================================

    public function testCmpsw16bitEqual(): void
    {
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0x1234, 16);
        $this->writeMemory(0x2000, 0x1234, 16);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA7]); // CMPSW

        $this->assertTrue($this->getZeroFlag());
        $this->assertSame(0x1002, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2002, $this->getRegister(RegisterType::EDI));
    }

    public function testCmpsd32bitEqual(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xDEADBEEF, 32);
        $this->writeMemory(0x2000, 0xDEADBEEF, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA7]); // CMPSD

        $this->assertTrue($this->getZeroFlag());
        $this->assertSame(0x1004, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
    }

    public function testCmpsd32bitNotEqual(): void
    {
        $this->setOperandSize(32);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0x00000001, 32);
        $this->writeMemory(0x2000, 0xFFFFFFFF, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA7]); // CMPSD

        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // Direction Flag Interaction Tests
    // ========================================

    public function testDirectionFlagAffectsAllStringOps(): void
    {
        // Forward direction
        $this->setDirectionFlag(false);

        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setRegister(RegisterType::EAX, 0x55);
        $this->writeMemory(0x1000, 0xAA, 8);

        $this->executeBytes([0xA4]); // MOVSB
        $this->assertSame(0x1001, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));

        // Backward direction
        $this->setDirectionFlag(true);

        $this->setRegister(RegisterType::ESI, 0x1010);
        $this->setRegister(RegisterType::EDI, 0x2010);
        $this->writeMemory(0x1010, 0xBB, 8);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xA4));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->movsb->process($this->runtime, 0xA4);

        $this->assertSame(0x100F, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x200F, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // Edge Case Tests
    // ========================================

    public function testStringOpsDoNotAffectCarryFlagForMovs(): void
    {
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xFF, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA4]); // MOVSB

        // MOVSB should not affect flags
        $this->assertTrue($this->getCarryFlag());
    }

    public function testStringOpsDoNotAffectCarryFlagForStos(): void
    {
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::EAX, 0x00);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAA]); // STOSB

        // STOSB should not affect flags
        $this->assertTrue($this->getCarryFlag());
    }

    public function testStringOpsDoNotAffectCarryFlagForLods(): void
    {
        $this->setCarryFlag(true);
        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->writeMemory(0x1000, 0xFF, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAC]); // LODSB

        // LODSB should not affect flags
        $this->assertTrue($this->getCarryFlag());
    }

    public function testScasAndCmpsUpdateFlags(): void
    {
        // SCASB and CMPSB should update flags
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);

        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x2000, 0x10, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xAE]); // SCASB

        // Should set ZF because AL == [ES:DI]
        $this->assertTrue($this->getZeroFlag());
    }

    // ========================================
    // Protected Mode Tests
    // ========================================

    public function testMovsbInProtectedMode(): void
    {
        $this->setProtectedMode(true);
        $this->setOperandSize(32);
        $this->setAddressSize(32);

        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xDE, 8);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA4]); // MOVSB

        $this->assertSame(0xDE, $this->readMemory(0x2000, 8));
        $this->assertSame(0x1001, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2001, $this->getRegister(RegisterType::EDI));
    }

    public function testMovsdInProtectedMode(): void
    {
        $this->setProtectedMode(true);
        $this->setOperandSize(32);
        $this->setAddressSize(32);

        $this->setRegister(RegisterType::ESI, 0x1000);
        $this->setRegister(RegisterType::EDI, 0x2000);
        $this->writeMemory(0x1000, 0xCAFEBABE, 32);
        $this->setDirectionFlag(false);

        $this->executeBytes([0xA5]); // MOVSD

        $this->assertSame(0xCAFEBABE, $this->readMemory(0x2000, 32));
        $this->assertSame(0x1004, $this->getRegister(RegisterType::ESI));
        $this->assertSame(0x2004, $this->getRegister(RegisterType::EDI));
    }
}
