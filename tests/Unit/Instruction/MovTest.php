<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Mov;
use PHPMachineEmulator\Instruction\Intel\x86\MovImm8;
use PHPMachineEmulator\Instruction\Intel\x86\MovMem;
use PHPMachineEmulator\Instruction\Intel\x86\MovRm16;
use PHPMachineEmulator\Instruction\Intel\x86\MovImmToRm;
use PHPMachineEmulator\Instruction\Intel\x86\MovMoffset;
use PHPMachineEmulator\Instruction\Intel\x86\MovFrom8BitReg;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for MOV instructions
 *
 * MOV r/m16/32, r16/32: 0x89
 * MOV r16/32, r/m16/32: 0x8B
 * MOV r/m8, r8: 0x88
 * MOV r8, r/m8: 0x8A
 * MOV r8, imm8: 0xB0-0xB3 (AL, CL, DL, BL)
 * MOV r8h, imm8: 0xB4-0xB7 (AH, CH, DH, BH)
 * MOV r16/32, imm16/32: 0xB8-0xBF
 * MOV r/m8, imm8: 0xC6
 * MOV r/m16/32, imm16/32: 0xC7
 * MOV AL, moffs8: 0xA0
 * MOV AX/EAX, moffs16/32: 0xA1
 * MOV moffs8, AL: 0xA2
 * MOV moffs16/32, AX/EAX: 0xA3
 */
class MovTest extends InstructionTestCase
{
    private Mov $mov;
    private MovImm8 $movImm8;
    private MovMem $movMem;
    private MovRm16 $movRm16;
    private MovImmToRm $movImmToRm;
    private MovMoffset $movMoffset;
    private MovFrom8BitReg $movFrom8BitReg;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->mov = new Mov($instructionList);
        $this->movImm8 = new MovImm8($instructionList);
        $this->movMem = new MovMem($instructionList);
        $this->movRm16 = new MovRm16($instructionList);
        $this->movImmToRm = new MovImmToRm($instructionList);
        $this->movMoffset = new MovMoffset($instructionList);
        $this->movFrom8BitReg = new MovFrom8BitReg($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match (true) {
            $opcode === 0x89 => $this->mov,
            $opcode === 0x8A => $this->movMem,
            $opcode === 0x8B => $this->movRm16,
            $opcode === 0x88 => $this->movFrom8BitReg,
            $opcode >= 0xB0 && $opcode <= 0xBF => $this->movImm8,
            $opcode === 0xC6 || $opcode === 0xC7 => $this->movImmToRm,
            $opcode >= 0xA0 && $opcode <= 0xA3 => $this->movMoffset,
            default => null,
        };
    }

    // ========================================
    // MOV r16/32, imm16/32 (0xB8-0xBF) Tests
    // ========================================

    public function testMovEaxImm32(): void
    {
        // MOV EAX, 0x12345678
        $this->executeBytes([0xB8, 0x78, 0x56, 0x34, 0x12]);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testMovEcxImm32(): void
    {
        // MOV ECX, 0xDEADBEEF
        $this->executeBytes([0xB9, 0xEF, 0xBE, 0xAD, 0xDE]);

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::ECX));
    }

    public function testMovEdxImm32(): void
    {
        // MOV EDX, 0xCAFEBABE
        $this->executeBytes([0xBA, 0xBE, 0xBA, 0xFE, 0xCA]);

        $this->assertSame(0xCAFEBABE, $this->getRegister(RegisterType::EDX));
    }

    public function testMovEbxImm32(): void
    {
        // MOV EBX, 0x11223344
        $this->executeBytes([0xBB, 0x44, 0x33, 0x22, 0x11]);

        $this->assertSame(0x11223344, $this->getRegister(RegisterType::EBX));
    }

    public function testMovEspImm32(): void
    {
        // MOV ESP, 0x00008000
        $this->executeBytes([0xBC, 0x00, 0x80, 0x00, 0x00]);

        $this->assertSame(0x00008000, $this->getRegister(RegisterType::ESP));
    }

    public function testMovEbpImm32(): void
    {
        // MOV EBP, 0x00007000
        $this->executeBytes([0xBD, 0x00, 0x70, 0x00, 0x00]);

        $this->assertSame(0x00007000, $this->getRegister(RegisterType::EBP));
    }

    public function testMovEsiImm32(): void
    {
        // MOV ESI, 0x55555555
        $this->executeBytes([0xBE, 0x55, 0x55, 0x55, 0x55]);

        $this->assertSame(0x55555555, $this->getRegister(RegisterType::ESI));
    }

    public function testMovEdiImm32(): void
    {
        // MOV EDI, 0xAAAAAAAA
        $this->executeBytes([0xBF, 0xAA, 0xAA, 0xAA, 0xAA]);

        $this->assertSame(0xAAAAAAAA, $this->getRegister(RegisterType::EDI));
    }

    public function testMovAxImm16(): void
    {
        // 16-bit mode: MOV AX, 0x1234
        $this->setOperandSize(16);
        $this->executeBytes([0xB8, 0x34, 0x12]);

        $this->assertSame(0x1234, $this->getRegister(RegisterType::EAX, 16));
    }

    // ========================================
    // MOV r8, imm8 (0xB0-0xB3) Tests
    // ========================================

    public function testMovAlImm8(): void
    {
        // MOV AL, 0xAB
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xB0, 0xAB]);

        $this->assertSame(0xAB, $this->getRegister(RegisterType::EAX) & 0xFF);
    }

    public function testMovClImm8(): void
    {
        // MOV CL, 0xCD
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0xB1, 0xCD]);

        $this->assertSame(0xCD, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testMovDlImm8(): void
    {
        // MOV DL, 0xEF
        $this->setRegister(RegisterType::EDX, 0x00000000);
        $this->executeBytes([0xB2, 0xEF]);

        $this->assertSame(0xEF, $this->getRegister(RegisterType::EDX) & 0xFF);
    }

    public function testMovBlImm8(): void
    {
        // MOV BL, 0x12
        $this->setRegister(RegisterType::EBX, 0x00000000);
        $this->executeBytes([0xB3, 0x12]);

        $this->assertSame(0x12, $this->getRegister(RegisterType::EBX) & 0xFF);
    }

    // ========================================
    // MOV r8h, imm8 (0xB4-0xB7) Tests
    // ========================================

    public function testMovAhImm8(): void
    {
        // MOV AH, 0xAA
        $this->setRegister(RegisterType::EAX, 0x000000FF);
        $this->executeBytes([0xB4, 0xAA]);

        $this->assertSame(0x0000AAFF, $this->getRegister(RegisterType::EAX));
    }

    public function testMovChImm8(): void
    {
        // MOV CH, 0xBB
        $this->setRegister(RegisterType::ECX, 0x000000CC);
        $this->executeBytes([0xB5, 0xBB]);

        $this->assertSame(0x0000BBCC, $this->getRegister(RegisterType::ECX));
    }

    public function testMovDhImm8(): void
    {
        // MOV DH, 0xDD
        $this->setRegister(RegisterType::EDX, 0x000000EE);
        $this->executeBytes([0xB6, 0xDD]);

        $this->assertSame(0x0000DDEE, $this->getRegister(RegisterType::EDX));
    }

    public function testMovBhImm8(): void
    {
        // MOV BH, 0x11
        $this->setRegister(RegisterType::EBX, 0x00000022);
        $this->executeBytes([0xB7, 0x11]);

        $this->assertSame(0x00001122, $this->getRegister(RegisterType::EBX));
    }

    // ========================================
    // MOV r/m16/32, r16/32 (0x89) Tests
    // ========================================

    public function testMovR32R32(): void
    {
        // MOV EBX, EAX (ModRM = 0xD8 = 11 011 000)
        // mode=11 (register), reg=011 (EBX), r/m=000 (EAX)
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::EBX, 0x00000000);
        $this->executeBytes([0x89, 0xC3]); // MOV EBX, EAX

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EBX));
    }

    public function testMovMemR32(): void
    {
        // MOV [0x1000], EAX - direct memory addressing
        // ModRM = 0x05 = 00 000 101 (mode=00, reg=EAX, rm=101 = disp32)
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);
        $this->executeBytes([0x89, 0x05, 0x00, 0x10, 0x00, 0x00]); // MOV [0x1000], EAX

        $this->assertSame(0xDEADBEEF, $this->readMemory(0x1000, 32));
    }

    public function testMovMemR32WithDisplacement8(): void
    {
        // MOV [EBP+0x10], ECX
        // ModRM = 0x4D = 01 001 101 (mode=01 disp8, reg=ECX, rm=101=EBP)
        $this->setRegister(RegisterType::EBP, 0x2000);
        $this->setRegister(RegisterType::ECX, 0xCAFEBABE);
        $this->executeBytes([0x89, 0x4D, 0x10]); // MOV [EBP+0x10], ECX

        $this->assertSame(0xCAFEBABE, $this->readMemory(0x2010, 32));
    }

    public function testMovMemR32WithDisplacement32(): void
    {
        // MOV [EBP+0x100], EDX
        // ModRM = 0x95 = 10 010 101 (mode=10 disp32, reg=EDX, rm=101=EBP)
        $this->setRegister(RegisterType::EBP, 0x3000);
        $this->setRegister(RegisterType::EDX, 0x11223344);
        $this->executeBytes([0x89, 0x95, 0x00, 0x01, 0x00, 0x00]); // MOV [EBP+0x100], EDX

        $this->assertSame(0x11223344, $this->readMemory(0x3100, 32));
    }

    // ========================================
    // MOV r16/32, r/m16/32 (0x8B) Tests
    // ========================================

    public function testMovR32R32From(): void
    {
        // MOV EAX, EBX (ModRM = 0xC3 = 11 000 011)
        $this->setRegister(RegisterType::EBX, 0xAAAABBBB);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x8B, 0xC3]); // MOV EAX, EBX

        $this->assertSame(0xAAAABBBB, $this->getRegister(RegisterType::EAX));
    }

    public function testMovR32Mem(): void
    {
        // MOV EAX, [0x1000]
        $this->writeMemory(0x1000, 0x55667788, 32);
        $this->executeBytes([0x8B, 0x05, 0x00, 0x10, 0x00, 0x00]); // MOV EAX, [0x1000]

        $this->assertSame(0x55667788, $this->getRegister(RegisterType::EAX));
    }

    public function testMovR32MemWithIndexScale(): void
    {
        // MOV EAX, [EBX+ECX*4]
        // ModRM = 0x04 (rm=100 indicates SIB byte follows)
        // SIB = 0x8B = 10 001 011 (scale=2 (x4), index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x1000);
        $this->setRegister(RegisterType::ECX, 0x10);
        $this->writeMemory(0x1040, 0x99887766, 32); // 0x1000 + 0x10*4 = 0x1040
        $this->executeBytes([0x8B, 0x04, 0x8B]); // MOV EAX, [EBX+ECX*4]

        $this->assertSame(0x99887766, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // MOV r/m8, r8 (0x88) Tests
    // ========================================

    public function testMovR8R8(): void
    {
        // MOV CL, AL (ModRM = 0xC1 = 11 000 001)
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0x88, 0xC1]); // MOV CL, AL

        $this->assertSame(0xAB, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testMovMem8R8(): void
    {
        // MOV [0x1000], AL
        $this->setRegister(RegisterType::EAX, 0x000000FF);
        $this->executeBytes([0x88, 0x05, 0x00, 0x10, 0x00, 0x00]); // MOV [0x1000], AL

        $this->assertSame(0xFF, $this->readMemory(0x1000, 8));
    }

    // ========================================
    // MOV r8, r/m8 (0x8A) Tests
    // ========================================

    public function testMovR8R8From(): void
    {
        // MOV AL, CL (ModRM = 0xC1 = 11 000 001)
        $this->setRegister(RegisterType::ECX, 0x000000CD);
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0x8A, 0xC1]); // MOV AL, CL

        $this->assertSame(0xCD, $this->getRegister(RegisterType::EAX) & 0xFF);
    }

    public function testMovR8Mem(): void
    {
        // MOV AL, [0x1000]
        $this->writeMemory(0x1000, 0xEE, 8);
        $this->executeBytes([0x8A, 0x05, 0x00, 0x10, 0x00, 0x00]); // MOV AL, [0x1000]

        $this->assertSame(0xEE, $this->getRegister(RegisterType::EAX) & 0xFF);
    }

    // ========================================
    // MOV r/m8, imm8 (0xC6) Tests
    // ========================================

    public function testMovRm8Imm8Register(): void
    {
        // MOV CL, 0x55 (ModRM = 0xC1 = 11 000 001)
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0xC6, 0xC1, 0x55]); // MOV CL, 0x55

        $this->assertSame(0x55, $this->getRegister(RegisterType::ECX) & 0xFF);
    }

    public function testMovRm8Imm8Memory(): void
    {
        // MOV BYTE PTR [0x1000], 0xAA
        $this->executeBytes([0xC6, 0x05, 0x00, 0x10, 0x00, 0x00, 0xAA]);

        $this->assertSame(0xAA, $this->readMemory(0x1000, 8));
    }

    // ========================================
    // MOV r/m16/32, imm16/32 (0xC7) Tests
    // ========================================

    public function testMovRm32Imm32Register(): void
    {
        // MOV ECX, 0x12345678 (ModRM = 0xC1 = 11 000 001)
        $this->setRegister(RegisterType::ECX, 0x00000000);
        $this->executeBytes([0xC7, 0xC1, 0x78, 0x56, 0x34, 0x12]); // MOV ECX, 0x12345678

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::ECX));
    }

    public function testMovRm32Imm32Memory(): void
    {
        // MOV DWORD PTR [0x1000], 0xDEADBEEF
        $this->executeBytes([0xC7, 0x05, 0x00, 0x10, 0x00, 0x00, 0xEF, 0xBE, 0xAD, 0xDE]);

        $this->assertSame(0xDEADBEEF, $this->readMemory(0x1000, 32));
    }

    public function testMovRm16Imm16(): void
    {
        // 16-bit mode: MOV WORD PTR [0x1000], 0x1234
        $this->setOperandSize(16);
        $this->setAddressSize(32);
        $this->executeBytes([0xC7, 0x05, 0x00, 0x10, 0x00, 0x00, 0x34, 0x12]);

        $this->assertSame(0x1234, $this->readMemory(0x1000, 16));
    }

    // ========================================
    // MOV AL, moffs8 (0xA0) Tests
    // ========================================

    public function testMovAlMoffs8(): void
    {
        // MOV AL, [0x1000]
        $this->writeMemory(0x1000, 0x77, 8);
        $this->executeBytes([0xA0, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x77, $this->getRegister(RegisterType::EAX) & 0xFF);
    }

    public function testMovAlMoffs16Address(): void
    {
        // 16-bit address mode: MOV AL, [0x1000]
        $this->setAddressSize(16);
        $this->writeMemory(0x1000, 0x88, 8);
        $this->executeBytes([0xA0, 0x00, 0x10]);

        $this->assertSame(0x88, $this->getRegister(RegisterType::EAX) & 0xFF);
    }

    // ========================================
    // MOV AX/EAX, moffs16/32 (0xA1) Tests
    // ========================================

    public function testMovEaxMoffs32(): void
    {
        // MOV EAX, [0x1000]
        $this->writeMemory(0x1000, 0x11223344, 32);
        $this->executeBytes([0xA1, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x11223344, $this->getRegister(RegisterType::EAX));
    }

    public function testMovAxMoffs16(): void
    {
        // 16-bit mode: MOV AX, [0x1000]
        $this->setOperandSize(16);
        $this->setAddressSize(32);
        $this->writeMemory(0x1000, 0x5566, 16);
        $this->executeBytes([0xA1, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x5566, $this->getRegister(RegisterType::EAX, 16));
    }

    // ========================================
    // MOV moffs8, AL (0xA2) Tests
    // ========================================

    public function testMovMoffs8Al(): void
    {
        // MOV [0x1000], AL
        $this->setRegister(RegisterType::EAX, 0x000000AB);
        $this->executeBytes([0xA2, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0xAB, $this->readMemory(0x1000, 8));
    }

    // ========================================
    // MOV moffs16/32, AX/EAX (0xA3) Tests
    // ========================================

    public function testMovMoffs32Eax(): void
    {
        // MOV [0x1000], EAX
        $this->setRegister(RegisterType::EAX, 0xAABBCCDD);
        $this->executeBytes([0xA3, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0xAABBCCDD, $this->readMemory(0x1000, 32));
    }

    public function testMovMoffs16Ax(): void
    {
        // 16-bit mode: MOV [0x1000], AX
        $this->setOperandSize(16);
        $this->setAddressSize(32);
        $this->setRegister(RegisterType::EAX, 0x1234);
        $this->executeBytes([0xA3, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x1234, $this->readMemory(0x1000, 16));
    }

    // ========================================
    // Flag Tests - MOV should NOT affect flags
    // ========================================

    public function testMovDoesNotAffectFlags(): void
    {
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);

        $this->executeBytes([0xB8, 0x00, 0x00, 0x00, 0x00]); // MOV EAX, 0

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testMovPreservesClearFlags(): void
    {
        $this->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);

        $this->executeBytes([0xB8, 0xFF, 0xFF, 0xFF, 0xFF]); // MOV EAX, -1

        // Flags should remain unchanged
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
    }

    // ========================================
    // Edge Case Tests
    // ========================================

    public function testMovZero(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->executeBytes([0xB8, 0x00, 0x00, 0x00, 0x00]); // MOV EAX, 0

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
    }

    public function testMovMaxValue(): void
    {
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->executeBytes([0xB8, 0xFF, 0xFF, 0xFF, 0xFF]); // MOV EAX, 0xFFFFFFFF

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    public function testMovLowBytePreservesHighBytes(): void
    {
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xB0, 0xAB]); // MOV AL, 0xAB

        // High bytes should be preserved
        $this->assertSame(0x123456AB, $this->getRegister(RegisterType::EAX));
    }

    public function testMovHighBytePreservesOtherBytes(): void
    {
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->executeBytes([0xB4, 0xCD]); // MOV AH, 0xCD

        // Other bytes should be preserved
        $this->assertSame(0x1234CD78, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // Protected Mode Tests
    // ========================================

    public function testMovInProtectedMode(): void
    {
        $this->setProtectedMode(true);

        $this->executeBytes([0xB8, 0x78, 0x56, 0x34, 0x12]); // MOV EAX, 0x12345678

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testMovMemoryInProtectedMode(): void
    {
        $this->setProtectedMode(true);

        // MOV DWORD PTR [0x1000], 0xCAFEBABE
        $this->executeBytes([0xC7, 0x05, 0x00, 0x10, 0x00, 0x00, 0xBE, 0xBA, 0xFE, 0xCA]);

        $this->assertSame(0xCAFEBABE, $this->readMemory(0x1000, 32));
    }

    // ========================================
    // Chained MOV Tests
    // ========================================

    public function testChainedMov(): void
    {
        // MOV EAX, 0x11111111
        $this->executeBytes([0xB8, 0x11, 0x11, 0x11, 0x11]);

        // MOV EBX, EAX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x89) . chr(0xC3));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->mov->process($this->runtime, [0x89]);

        // MOV ECX, EBX
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0x89) . chr(0xD9)); // 0xD9 = 11 011 001 (reg=EBX, rm=ECX)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->mov->process($this->runtime, [0x89]);

        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EAX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::EBX));
        $this->assertSame(0x11111111, $this->getRegister(RegisterType::ECX));
    }

    public function testMovToMemoryAndBack(): void
    {
        // MOV EAX, 0xDEADBEEF
        $this->setRegister(RegisterType::EAX, 0xDEADBEEF);

        // MOV [0x1000], EAX
        $this->executeBytes([0x89, 0x05, 0x00, 0x10, 0x00, 0x00]);

        // Clear EAX
        $this->setRegister(RegisterType::EAX, 0x00000000);

        // MOV EAX, [0x1000]
        $this->memoryStream->setOffset(0);
        $code = chr(0x8B) . chr(0x05) . chr(0x00) . chr(0x10) . chr(0x00) . chr(0x00);
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->movRm16->process($this->runtime, [0x8B]);

        $this->assertSame(0xDEADBEEF, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // 32-bit SIB Addressing Mode Tests
    // ========================================

    public function testMovWithSibBaseAndIndex(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [EBX+ECX*1]
        // ModRM = 0x04 = 00 000 100 (mode=00, reg=EAX, r/m=100=SIB)
        // SIB = 0x0B = 00 001 011 (scale=1, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x1000);
        $this->setRegister(RegisterType::ECX, 0x100);
        $this->writeMemory(0x1100, 0xAABBCCDD, 32);

        $this->executeBytes([0x8B, 0x04, 0x0B]); // MOV EAX, [EBX+ECX*1]

        $this->assertSame(0xAABBCCDD, $this->getRegister(RegisterType::EAX));
    }

    public function testMovWithSibScaledIndex(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [EBX+ECX*4]
        // SIB = 0x8B = 10 001 011 (scale=4, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x2000);
        $this->setRegister(RegisterType::ECX, 0x40);
        $this->writeMemory(0x2100, 0x11223344, 32); // 0x2000 + 0x40*4 = 0x2100

        $this->executeBytes([0x8B, 0x04, 0x8B]); // MOV EAX, [EBX+ECX*4]

        $this->assertSame(0x11223344, $this->getRegister(RegisterType::EAX));
    }

    public function testMovWithSibAndDisplacement8(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [EBX+ECX*2+0x10]
        // ModRM = 0x44 = 01 000 100 (mode=01 disp8, reg=EAX, r/m=100=SIB)
        // SIB = 0x4B = 01 001 011 (scale=2, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x3000);
        $this->setRegister(RegisterType::ECX, 0x20);
        $this->writeMemory(0x3050, 0x55667788, 32); // 0x3000 + 0x20*2 + 0x10 = 0x3050

        $this->executeBytes([0x8B, 0x44, 0x4B, 0x10]); // MOV EAX, [EBX+ECX*2+0x10]

        $this->assertSame(0x55667788, $this->getRegister(RegisterType::EAX));
    }

    public function testMovWithSibScale8(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [EBX+ECX*8]
        // SIB = 0xCB = 11 001 011 (scale=8, index=ECX, base=EBX)
        $this->setRegister(RegisterType::EBX, 0x4000);
        $this->setRegister(RegisterType::ECX, 0x10);
        $this->writeMemory(0x4080, 0x99AABBCC, 32); // 0x4000 + 0x10*8 = 0x4080

        $this->executeBytes([0x8B, 0x04, 0xCB]); // MOV EAX, [EBX+ECX*8]

        $this->assertSame(0x99AABBCC, $this->getRegister(RegisterType::EAX));
    }

    public function testMovWriteWithSib(): void
    {
        $this->setProtectedMode(true);

        // MOV [EDI+ESI*1], EAX
        // ModRM = 0x04 (r/m=100=SIB), but this is for 0x89 so reg encodes source
        // 0x89 = MOV r/m, r : ModRM = 0x04 = 00 000 100 (reg=EAX, r/m=SIB)
        // SIB = 0x37 = 00 110 111 (scale=1, index=ESI, base=EDI)
        $this->setRegister(RegisterType::EDI, 0x5000);
        $this->setRegister(RegisterType::ESI, 0x200);
        $this->setRegister(RegisterType::EAX, 0xDEADC0DE);

        $this->executeBytes([0x89, 0x04, 0x37]); // MOV [EDI+ESI*1], EAX

        $this->assertSame(0xDEADC0DE, $this->readMemory(0x5200, 32));
    }

    public function testMov8BitWithSib(): void
    {
        $this->setProtectedMode(true);

        // MOV AL, [EBX+ECX*1]
        // 0x8A = MOV r8, r/m8
        // ModRM = 0x04, SIB = 0x0B
        $this->setRegister(RegisterType::EBX, 0x6000);
        $this->setRegister(RegisterType::ECX, 0x50);
        $this->writeMemory(0x6050, 0xAB, 8);

        $this->executeBytes([0x8A, 0x04, 0x0B]); // MOV AL, [EBX+ECX*1]

        $this->assertSame(0xAB, $this->getRegister(RegisterType::EAX) & 0xFF);
    }

    public function testMovWithSibNoIndex(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [ESP] - ESP as base, no index (index=100 means no index)
        // ModRM = 0x04, SIB = 0x24 = 00 100 100 (scale=1, index=ESP=none, base=ESP)
        $this->setRegister(RegisterType::ESP, 0x7000);
        $this->writeMemory(0x7000, 0x12345678, 32);

        $this->executeBytes([0x8B, 0x04, 0x24]); // MOV EAX, [ESP]

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    public function testMovWithSibDisp32Only(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [ECX*4+disp32] - base=101 with mod=00 means disp32 only
        // ModRM = 0x04 = 00 000 100
        // SIB = 0x8D = 10 001 101 (scale=4, index=ECX, base=101=disp32)
        $this->setRegister(RegisterType::ECX, 0x10);
        $this->writeMemory(0x1040, 0xFEDCBA98, 32); // 0x1000 + 0x10*4 = 0x1040

        // SIB with base=101 and mod=00 means [index*scale + disp32]
        $this->executeBytes([0x8B, 0x04, 0x8D, 0x00, 0x10, 0x00, 0x00]); // MOV EAX, [ECX*4+0x1000]

        $this->assertSame(0xFEDCBA98, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // 32-bit Address Wraparound Tests
    // ========================================

    public function testMovAddressWithLargeIndex(): void
    {
        // Test SIB addressing with large index values
        $this->setProtectedMode(true);

        // MOV EAX, [EBX+ECX*1] where ECX is a large value
        $this->setRegister(RegisterType::EBX, 0x1000);
        $this->setRegister(RegisterType::ECX, 0x500);
        $this->writeMemory(0x1500, 0xCAFEBABE, 32);

        $this->executeBytes([0x8B, 0x04, 0x0B]); // MOV EAX, [EBX+ECX*1]

        $this->assertSame(0xCAFEBABE, $this->getRegister(RegisterType::EAX));
    }

    public function testMovWithNegativeDisplacement(): void
    {
        $this->setProtectedMode(true);

        // MOV EAX, [EBX-16] using signed displacement
        // ModRM = 0x43 = 01 000 011 (mode=01 disp8, reg=EAX, r/m=EBX)
        // disp8 = 0xF0 = -16
        $this->setRegister(RegisterType::EBX, 0x1020);
        $this->writeMemory(0x1010, 0x13579BDF, 32); // 0x1020 - 16 = 0x1010

        $this->executeBytes([0x8B, 0x43, 0xF0]); // MOV EAX, [EBX-16]

        $this->assertSame(0x13579BDF, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // Real Mode 32-bit Addressing Tests
    // ========================================

    public function testMovRealModeWith32BitAddressing(): void
    {
        // Real mode with 32-bit address size (via 0x67 prefix simulation)
        $this->setProtectedMode(false);
        $this->setAddressSize(32);
        $this->setOperandSize(32);

        $this->setRegister(RegisterType::EBX, 0x1000);
        $this->setRegister(RegisterType::ECX, 0x100);
        $this->writeMemory(0x1100, 0x87654321, 32);

        $this->executeBytes([0x8B, 0x04, 0x0B]); // MOV EAX, [EBX+ECX*1]

        $this->assertSame(0x87654321, $this->getRegister(RegisterType::EAX));
    }
}
