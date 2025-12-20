<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Jmp;
use PHPMachineEmulator\Instruction\Intel\x86\JmpShort;
use PHPMachineEmulator\Instruction\Intel\x86\JmpFar;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\ExecutionStatus;

/**
 * Tests for large/far jump instructions.
 *
 * Tests verify:
 * - Near jumps (rel8, rel16, rel32)
 * - Far jumps (ptr16:16, ptr16:32)
 * - Segment crossing jumps
 * - Protected mode far jumps
 */
class LargeJumpTest extends InstructionTestCase
{
    private ?Jmp $jmp = null;
    private ?JmpShort $jmpShort = null;
    private ?JmpFar $jmpFar = null;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->jmp = new Jmp($instructionList);
        $this->jmpShort = new JmpShort($instructionList);
        $this->jmpFar = new JmpFar($instructionList);
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xEB => $this->jmpShort,
            0xE9 => $this->jmp,
            0xEA => $this->jmpFar,
            default => null,
        };
    }

    // ========================================
    // Short Jump (rel8) Tests
    // ========================================

    public function testShortJumpForward(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // JMP short +5 (EB 05)
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->write(chr(0xEB) . chr(0x05));
        $this->memoryStream->setOffset(0x100);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmpShort->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // IP should be at 0x102 + 5 = 0x107
        $newOffset = $this->memoryStream->offset();
        $this->assertTrue($newOffset >= 0x105, "Jump should be forward, got: 0x" . dechex($newOffset));
    }

    public function testShortJumpBackward(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // JMP short -10 (EB F6 = -10 in two's complement)
        // Starting at 0x200 to avoid wrap-around issues
        $this->memoryStream->setOffset(0x200);
        $this->memoryStream->write(chr(0xEB) . chr(0xF6));
        $this->memoryStream->setOffset(0x200);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmpShort->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Jump backward: 0x202 - 10 = 0x1F8
        $newOffset = $this->memoryStream->offset();
        $this->assertTrue($newOffset < 0x200, "Jump should be backward from 0x200, got: 0x" . dechex($newOffset));
    }

    // ========================================
    // Near Jump (rel16/rel32) Tests
    // ========================================

    public function testNearJump16Forward(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // JMP near +0x1000 (E9 00 10)
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->write(chr(0xE9) . chr(0x00) . chr(0x10));
        $this->memoryStream->setOffset(0x100);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmp->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // IP should be at 0x103 + 0x1000 = 0x1103
        $this->assertSame(0x1103, $this->memoryStream->offset());
    }

    public function testNearJump32Forward(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::CS, 0x0008);

        // JMP near +0x10000 (E9 00 00 01 00)
        $this->memoryStream->setOffset(0x1000);
        $this->memoryStream->write(chr(0xE9) . chr(0x00) . chr(0x00) . chr(0x01) . chr(0x00));
        $this->memoryStream->setOffset(0x1000);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmp->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // IP should be at 0x1005 + 0x10000 = 0x11005
        $this->assertSame(0x11005, $this->memoryStream->offset());
    }

    public function testNearJump32Backward(): void
    {
        $this->setProtectedMode32();
        $this->setRegister(RegisterType::CS, 0x0008);

        // JMP near -0x100 (E9 00 FF FF FF = -256 in 32-bit two's complement)
        $this->memoryStream->setOffset(0x1000);
        $this->memoryStream->write(chr(0xE9) . chr(0x00) . chr(0xFF) . chr(0xFF) . chr(0xFF));
        $this->memoryStream->setOffset(0x1000);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmp->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // IP should be at 0x1005 - 0x100 = 0xF05
        $this->assertSame(0xF05, $this->memoryStream->offset());
    }

    // ========================================
    // Wrap-around Tests
    // ========================================

    public function testNearJump16WrapAround(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // Jump that wraps around 16-bit address space
        $this->memoryStream->setOffset(0xFFF0);
        $this->memoryStream->write(chr(0xE9) . chr(0x20) . chr(0x00)); // JMP +0x20
        $this->memoryStream->setOffset(0xFFF0);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmp->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // 0xFFF3 + 0x20 = 0x10013, masked to 16-bit = 0x0013
        $expectedIp = (0xFFF3 + 0x20) & 0xFFFF;
        $this->assertSame($expectedIp, $this->memoryStream->offset() & 0xFFFF);
    }

    // ========================================
    // Large Displacement Tests
    // ========================================

    public function testMaxPositiveRel16(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // JMP near +0x7FFF (max positive 16-bit signed)
        $this->memoryStream->setOffset(0x0100);
        $this->memoryStream->write(chr(0xE9) . chr(0xFF) . chr(0x7F));
        $this->memoryStream->setOffset(0x0100);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmp->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // IP = 0x103 + 0x7FFF = 0x8102
        $this->assertSame(0x8102, $this->memoryStream->offset());
    }

    public function testMaxNegativeRel16(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // JMP near -0x8000 (0x8000 in two's complement)
        $this->memoryStream->setOffset(0x9000);
        $this->memoryStream->write(chr(0xE9) . chr(0x00) . chr(0x80));
        $this->memoryStream->setOffset(0x9000);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmp->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // IP = 0x9003 - 0x8000 = 0x1003
        $this->assertSame(0x1003, $this->memoryStream->offset());
    }

    // ========================================
    // Zero Displacement Tests
    // ========================================

    public function testJumpZeroDisplacement(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x0000);

        // JMP short +0 (EB 00) - jump to next instruction
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->write(chr(0xEB) . chr(0x00));
        $this->memoryStream->setOffset(0x100);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmpShort->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
        // Should jump to the instruction right after (0x102)
        $newOffset = $this->memoryStream->offset();
        $this->assertSame(0x102, $newOffset, "Zero displacement jump should land at 0x102");
    }

    // ========================================
    // Jump from Different Code Segments
    // ========================================

    public function testJumpWithNonZeroCS(): void
    {
        $this->setRealMode16();
        $this->setRegister(RegisterType::CS, 0x1000);

        // JMP short +5 (EB 05)
        // Linear address = CS * 16 + IP = 0x10000 + 0x100 = 0x10100
        $this->memoryStream->setOffset(0x10100);
        $this->memoryStream->write(chr(0xEB) . chr(0x05));
        $this->memoryStream->setOffset(0x10100);

        $opcode = $this->memoryStream->byte();
        $result = $this->jmpShort->process($this->runtime, [$opcode]);

        $this->assertSame(ExecutionStatus::SUCCESS, $result);
    }

    public function testFarJumpInProtectedModeCachesCsDescriptor(): void
    {
        $this->setProtectedMode32();

        // Build a minimal GDT with a flat 32-bit code segment at selector 0x0008.
        $gdtBase = 0x2000;
        $this->cpuContext->setGdtr($gdtBase, 0x0F); // 2 descriptors (16 bytes) - 1

        // Null descriptor (all zeros)
        for ($i = 0; $i < 8; $i++) {
            $this->writeMemory($gdtBase + $i, 0x00, 8);
        }

        // Code descriptor: base=0, limit=4GB, present, D=1, G=1 (0x9A, 0xCF)
        $codeDesc = [0xFF, 0xFF, 0x00, 0x00, 0x00, 0x9A, 0xCF, 0x00];
        foreach ($codeDesc as $i => $b) {
            $this->writeMemory($gdtBase + 8 + $i, $b, 8);
        }

        // JMP FAR ptr16:32 to 0x0008:0x1234
        $this->executeBytes([0xEA, 0x34, 0x12, 0x00, 0x00, 0x08, 0x00]);

        $this->assertSame(0x0008, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame(0x1234, $this->memoryStream->offset());

        $cached = $this->cpuContext->getCachedSegmentDescriptor(RegisterType::CS);
        $this->assertIsArray($cached);
        $this->assertSame(0x00000000, $cached['base']);
        $this->assertSame(0xFFFFFFFF, $cached['limit']);
        $this->assertTrue((bool) $cached['present']);
        $this->assertSame(32, $cached['default']);
        $this->assertSame(32, $this->cpuContext->defaultOperandSize());
        $this->assertSame(32, $this->cpuContext->defaultAddressSize());
    }
}
