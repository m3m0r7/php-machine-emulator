<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Xchg;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for XCHG instructions
 *
 * XCHG EAX, r32 / XCHG AX, r16: 0x90-0x97 (0x90 is NOP when EAX with itself)
 * XCHG r/m8, r8: 0x86
 * XCHG r/m16, r16 / XCHG r/m32, r32: 0x87
 */
class XchgTest extends InstructionTestCase
{
    private Xchg $xchg;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->xchg = new Xchg($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        if (in_array($opcode, [0x86, 0x87, 0x90, 0x91, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97], true)) {
            return $this->xchg;
        }
        return null;
    }

    // ========================================
    // XCHG EAX, r32 (0x90-0x97) Tests
    // ========================================

    public function testXchgEaxEax(): void
    {
        // 0x90 = XCHG EAX, EAX = NOP
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0x90]);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testXchgEaxEcx(): void
    {
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::ECX, 0x22222222);
        $this->executeBytes([0x91]); // XCHG EAX, ECX

        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::ECX));
    }

    public function testXchgEaxEdx(): void
    {
        $this->setRegister(RegisterType::EAX, 0xAAAAAAAA);
        $this->setRegister(RegisterType::EDX, 0xBBBBBBBB);
        $this->executeBytes([0x92]); // XCHG EAX, EDX

        $this->assertSame(0xBBBBBBBB, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0xAAAAAAAA, $this->getRegister(RegisterType::EDX));
    }

    public function testXchgEaxEbx(): void
    {
        $this->setRegister(RegisterType::EAX, 0xCAFEBABE);
        $this->setRegister(RegisterType::EBX, 0xDEADBEEF);
        $this->executeBytes([0x93]); // XCHG EAX, EBX

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0xCAFEBABE, $this->getRegister(RegisterType::EBX));
    }

    public function testXchgEaxEsp(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00001000);
        $this->setRegister(RegisterType::ESP, 0x00008000);
        $this->executeBytes([0x94]); // XCHG EAX, ESP

        $this->assertSame(0x00008000, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x00001000, $this->getRegister(RegisterType::ESP));
    }

    public function testXchgEaxEbp(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00002000);
        $this->setRegister(RegisterType::EBP, 0x00007000);
        $this->executeBytes([0x95]); // XCHG EAX, EBP

        $this->assertSame(0x00007000, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x00002000, $this->getRegister(RegisterType::EBP));
    }

    public function testXchgEaxEsi(): void
    {
        $this->setRegister(RegisterType::EAX, 0x33333333);
        $this->setRegister(RegisterType::ESI, 0x44444444);
        $this->executeBytes([0x96]); // XCHG EAX, ESI

        $this->assertSame(0x44444444, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x33333333, $this->getRegister(RegisterType::ESI));
    }

    public function testXchgEaxEdi(): void
    {
        $this->setRegister(RegisterType::EAX, 0x55555555);
        $this->setRegister(RegisterType::EDI, 0x66666666);
        $this->executeBytes([0x97]); // XCHG EAX, EDI

        $this->assertSame(0x66666666, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x55555555, $this->getRegister(RegisterType::EDI));
    }

    // ========================================
    // XCHG r/m32, r32 (0x87) Tests
    // ========================================

    public function testXchgR32R32(): void
    {
        // XCHG ECX, EBX (ModRM = 0xCB = 11 001 011)
        // mode=11 (register), reg=001 (ECX), r/m=011 (EBX)
        $this->setRegister(RegisterType::ECX, 0x11111111);
        $this->setRegister(RegisterType::EBX, 0x22222222);
        $this->executeBytes([0x87, 0xCB]); // XCHG EBX, ECX

        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ECX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EBX));
    }

    public function testXchgR32R32Same(): void
    {
        // XCHG ECX, ECX (ModRM = 0xC9 = 11 001 001)
        $this->setRegister(RegisterType::ECX, 0xABCDEF00);
        $this->executeBytes([0x87, 0xC9]); // XCHG ECX, ECX

        $this->assertSame(0xABCDEF00, $this->getRegister(RegisterType::ECX));
    }

    // ========================================
    // XCHG r/m8, r8 (0x86) Tests
    // ========================================

    public function testXchgR8R8(): void
    {
        // XCHG AL, CL (ModRM = 0xC8 = 11 001 000)
        // mode=11 (register), reg=001 (CL), r/m=000 (AL)
        $this->setRegister(RegisterType::EAX, 0x000000AA);
        $this->setRegister(RegisterType::ECX, 0x000000BB);
        $this->executeBytes([0x86, 0xC8]); // XCHG AL, CL

        $this->assertSame(0xBB, $this->getRegister(RegisterType::EAX, 8));
        $this->assertSame(0xAA, $this->getRegister(RegisterType::ECX, 8));
    }

    public function testXchgAhCh(): void
    {
        // XCHG AH, CH (ModRM = 0xEC = 11 101 100)
        // mode=11 (register), reg=101 (CH), r/m=100 (AH)
        $this->setRegister(RegisterType::EAX, 0x0000AA00); // AH = 0xAA
        $this->setRegister(RegisterType::ECX, 0x0000BB00); // CH = 0xBB
        $this->executeBytes([0x86, 0xEC]); // XCHG AH, CH

        // AH should now be 0xBB, CH should now be 0xAA
        $this->assertSame(0x0000BB00, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x0000AA00, $this->getRegister(RegisterType::ECX));
    }

    // ========================================
    // Double XCHG Tests (Should Return to Original)
    // ========================================

    public function testDoubleXchgReturnsToOriginal(): void
    {
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::EBX, 0x22222222);

        // First XCHG
        $this->executeBytes([0x93]); // XCHG EAX, EBX
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EBX));

        // Second XCHG (should restore)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x93));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->xchg->process($this->runtime, 0x93);

        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EBX));
    }

    // ========================================
    // Flag Tests
    // ========================================

    public function testXchgDoesNotAffectFlags(): void
    {
        // XCHG should not affect any flags
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);

        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::EBX, 0x22222222);
        $this->executeBytes([0x93]); // XCHG EAX, EBX

        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testXchgPreservesClearFlags(): void
    {
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);

        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::EBX, 0x00000000);
        $this->executeBytes([0x93]); // XCHG EAX, EBX

        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testXchgWithZero(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::EBX, 0x00000000);
        $this->executeBytes([0x93]); // XCHG EAX, EBX

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EBX));
    }

    public function testXchgWithMaxValue(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000001);
        $this->setRegister(RegisterType::EBX, 0xFFFFFFFF);
        $this->executeBytes([0x93]); // XCHG EAX, EBX

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x00000001, $this->getRegister(RegisterType::EBX));
    }

    public function testXchgThreeWay(): void
    {
        // Test cycling through three registers
        $this->setRegister(RegisterType::EAX, 0x11111111);
        $this->setRegister(RegisterType::EBX, 0x22222222);
        $this->setRegister(RegisterType::ECX, 0x33333333);

        // XCHG EAX, EBX
        $this->executeBytes([0x93]);
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EBX));

        // XCHG EAX, ECX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x91));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->xchg->process($this->runtime, 0x91);
        $this->assertSame(0x33333333, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ECX));

        // XCHG EAX, EBX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x93));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->xchg->process($this->runtime, 0x93);

        // Final state: EAX=11, EBX=33, ECX=22
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x33333333, $this->getRegister(RegisterType::EBX));
        $this->assertSame(0x22222222, $this->getRegister(RegisterType::ECX));
    }
}
