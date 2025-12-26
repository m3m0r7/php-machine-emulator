<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\CbwCwd;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

/**
 * Tests for CBW, CWDE, CWD, and CDQ instructions
 *
 * 0x98 in 16-bit mode: CBW  - Sign-extend AL to AX
 * 0x98 in 32-bit mode: CWDE - Sign-extend AX to EAX
 * 0x98 in 64-bit mode: CDQE - Sign-extend EAX to RAX
 * 0x99 in 16-bit mode: CWD  - Sign-extend AX to DX:AX
 * 0x99 in 32-bit mode: CDQ  - Sign-extend EAX to EDX:EAX
 * 0x99 in 64-bit mode: CQO  - Sign-extend RAX to RDX:RAX
 *
 * These instructions do not affect any flags.
 */
class CbwCwdTest extends InstructionTestCase
{
    private CbwCwd $cbwCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->cbwCwd = new CbwCwd($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0x98, 0x99 => $this->cbwCwd,
            default => null,
        };
    }

    // ========================================
    // CBW Tests (0x98 in 16-bit mode)
    // ========================================

    /**
     * Test CBW with positive AL (sign bit clear)
     * AL = 0x7F -> AX = 0x007F
     */
    public function testCbwPositiveValue(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x007F, 32);

        $this->executeBytes([0x98]);

        // AH should be 0x00 (sign extension of positive)
        $this->assertSame(0x007F, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
    }

    /**
     * Test CBW with negative AL (sign bit set)
     * AL = 0x80 -> AX = 0xFF80
     */
    public function testCbwNegativeValue(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x0080, 32);

        $this->executeBytes([0x98]);

        // AH should be 0xFF (sign extension of negative)
        $this->assertSame(0xFF80, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
    }

    /**
     * Test CBW with AL = 0xFF (all bits set)
     * AL = 0xFF -> AX = 0xFFFF
     */
    public function testCbwMaxNegative(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x00FF, 32);

        $this->executeBytes([0x98]);

        $this->assertSame(0xFFFF, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
    }

    /**
     * Test CBW with AL = 0x00 (zero)
     * AL = 0x00 -> AX = 0x0000
     */
    public function testCbwZero(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0xFF00, 32); // AH has garbage

        $this->executeBytes([0x98]);

        // AL = 0, so AH should be cleared to 0
        $this->assertSame(0x0000, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
    }

    // ========================================
    // CWDE Tests (0x98 in 32-bit mode)
    // ========================================

    /**
     * Test CWDE with positive AX (sign bit clear)
     * AX = 0x7FFF -> EAX = 0x00007FFF
     */
    public function testCwdePositiveValue(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x7FFF, 32);

        $this->executeBytes([0x98]);

        $this->assertSame(0x00007FFF, $this->getRegister(RegisterType::EAX, 32));
    }

    /**
     * Test CWDE with negative AX (sign bit set)
     * AX = 0x8000 -> EAX = 0xFFFF8000
     */
    public function testCwdeNegativeValue(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x8000, 32);

        $this->executeBytes([0x98]);

        $this->assertSame(0xFFFF8000, $this->getRegister(RegisterType::EAX, 32));
    }

    /**
     * Test CWDE with AX = 0xFFFF (-1)
     * AX = 0xFFFF -> EAX = 0xFFFFFFFF
     */
    public function testCwdeMaxNegative(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0xFFFF, 32);

        $this->executeBytes([0x98]);

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX, 32));
    }

    /**
     * Test CWDE with AX = 0x0000 (zero)
     */
    public function testCwdeZero(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0xFFFF0000, 32); // Upper bits have garbage

        $this->executeBytes([0x98]);

        // AX = 0, so upper bits should be cleared
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX, 32));
    }

    // ========================================
    // CWD Tests (0x99 in 16-bit mode)
    // ========================================

    /**
     * Test CWD with positive AX (sign bit clear)
     * AX = 0x7FFF -> DX:AX = 0x0000:0x7FFF
     */
    public function testCwdPositiveValue(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x7FFF, 32);
        $this->setRegister(RegisterType::EDX, 0xFFFF, 32); // DX has garbage

        $this->executeBytes([0x99]);

        $this->assertSame(0x7FFF, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
        $this->assertSame(0x0000, $this->getRegister(RegisterType::EDX, 32) & 0xFFFF);
    }

    /**
     * Test CWD with negative AX (sign bit set)
     * AX = 0x8000 -> DX:AX = 0xFFFF:0x8000
     */
    public function testCwdNegativeValue(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x8000, 32);
        $this->setRegister(RegisterType::EDX, 0x0000, 32);

        $this->executeBytes([0x99]);

        $this->assertSame(0x8000, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
        $this->assertSame(0xFFFF, $this->getRegister(RegisterType::EDX, 32) & 0xFFFF);
    }

    /**
     * Test CWD with AX = 0xFFFF (-1)
     * AX = 0xFFFF -> DX:AX = 0xFFFF:0xFFFF
     */
    public function testCwdMaxNegative(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0xFFFF, 32);
        $this->setRegister(RegisterType::EDX, 0x0000, 32);

        $this->executeBytes([0x99]);

        $this->assertSame(0xFFFF, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
        $this->assertSame(0xFFFF, $this->getRegister(RegisterType::EDX, 32) & 0xFFFF);
    }

    /**
     * Test CWD with AX = 0x0000 (zero)
     */
    public function testCwdZero(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x0000, 32);
        $this->setRegister(RegisterType::EDX, 0xFFFF, 32); // DX has garbage

        $this->executeBytes([0x99]);

        $this->assertSame(0x0000, $this->getRegister(RegisterType::EAX, 32) & 0xFFFF);
        $this->assertSame(0x0000, $this->getRegister(RegisterType::EDX, 32) & 0xFFFF);
    }

    // ========================================
    // CDQ Tests (0x99 in 32-bit mode)
    // ========================================

    /**
     * Test CDQ with positive EAX (sign bit clear)
     * EAX = 0x7FFFFFFF -> EDX:EAX = 0x00000000:0x7FFFFFFF
     */
    public function testCdqPositiveValue(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x7FFFFFFF, 32);
        $this->setRegister(RegisterType::EDX, 0xFFFFFFFF, 32); // EDX has garbage

        $this->executeBytes([0x99]);

        $this->assertSame(0x7FFFFFFF, $this->getRegister(RegisterType::EAX, 32));
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EDX, 32));
    }

    /**
     * Test CDQ with negative EAX (sign bit set)
     * EAX = 0x80000000 -> EDX:EAX = 0xFFFFFFFF:0x80000000
     */
    public function testCdqNegativeValue(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x80000000, 32);
        $this->setRegister(RegisterType::EDX, 0x00000000, 32);

        $this->executeBytes([0x99]);

        $this->assertSame(0x80000000, $this->getRegister(RegisterType::EAX, 32));
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EDX, 32));
    }

    /**
     * Test CDQ with EAX = 0xFFFFFFFF (-1)
     */
    public function testCdqMaxNegative(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF, 32);
        $this->setRegister(RegisterType::EDX, 0x00000000, 32);

        $this->executeBytes([0x99]);

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX, 32));
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EDX, 32));
    }

    /**
     * Test CDQ with EAX = 0x00000000 (zero)
     */
    public function testCdqZero(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x00000000, 32);
        $this->setRegister(RegisterType::EDX, 0xFFFFFFFF, 32); // EDX has garbage

        $this->executeBytes([0x99]);

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX, 32));
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EDX, 32));
    }

    // ========================================
    // Flag Tests (none should be affected)
    // ========================================

    /**
     * Test CBW does not affect flags
     */
    public function testCbwDoesNotAffectFlags(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x80, 32);

        // Set all flags to true
        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);
        $this->memoryAccessor->setSignFlag(true);
        $this->memoryAccessor->setOverflowFlag(true);

        $this->executeBytes([0x98]);

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    /**
     * Test CWDE does not affect flags
     */
    public function testCwdeDoesNotAffectFlags(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x8000, 32);

        // Set all flags to false
        $this->memoryAccessor->setCarryFlag(false);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(false);
        $this->memoryAccessor->setOverflowFlag(false);

        $this->executeBytes([0x98]);

        // Flags should remain unchanged
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    /**
     * Test CWD does not affect flags
     */
    public function testCwdDoesNotAffectFlags(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::EAX, 0x8000, 32);

        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);

        $this->executeBytes([0x99]);

        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    /**
     * Test CDQ does not affect flags
     */
    public function testCdqDoesNotAffectFlags(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::EAX, 0x80000000, 32);

        $this->memoryAccessor->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(false);
        $this->memoryAccessor->setSignFlag(true);

        $this->executeBytes([0x99]);

        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
    }

    // ========================================
    // 64-bit Mode (CDQE/CQO)
    // ========================================

    public function testCdqeSignExtendsEaxToRax(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setRex(0x8); // REX.W => operandSize=64

        $this->setRegister(RegisterType::EAX, 0x80000001, 32);

        $this->executeBytes([0x98]);

        $this->assertSame('0xffffffff80000001', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
    }

    public function testCqoSignExtendsRaxToRdxRax(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setRex(0x8); // REX.W => operandSize=64

        $this->setRegister(RegisterType::EAX, -1, 64); // 0xffffffffffffffff
        $this->setRegister(RegisterType::EDX, 0, 64);

        $this->executeBytes([0x99]);

        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::EDX, 64))->toHex());
        $this->assertSame('0xffffffffffffffff', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
    }
}
