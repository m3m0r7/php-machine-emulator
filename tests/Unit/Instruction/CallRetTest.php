<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Call;
use PHPMachineEmulator\Instruction\Intel\x86\Ret;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Option;
use Psr\Log\NullLogger;

/**
 * Tests for CALL and RET instructions
 *
 * CALL near: 0xE8 rel16/32
 * RET near: 0xC3
 * RET near imm16: 0xC2
 * RET far: 0xCB
 * RET far imm16: 0xCA
 */
class CallRetTest extends InstructionTestCase
{
    private Call $call;
    private Ret $ret;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $instructionList->method('register')->willReturn($register);

        $this->call = new Call($instructionList);
        $this->ret = new Ret($instructionList);

        // Set up Option to allow offset changes for CALL/RET
        $option = new Option(logger: new NullLogger(), shouldChangeOffset: true);
        $this->runtime->setOption($option);

        // CALL/RET tests use real mode to avoid protected mode segment issues
        $this->setRealMode32();
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return match ($opcode) {
            0xE8 => $this->call,
            0xC3, 0xC2, 0xCB, 0xCA => $this->ret,
            default => null,
        };
    }

    /**
     * Initialize stack pointer to a known location
     */
    private function initStack(int $sp = 0x8000): void
    {
        $this->setRegister(RegisterType::ESP, $sp);
    }

    /**
     * Get current stack pointer
     */
    private function getStackPointer(): int
    {
        return $this->getRegister(RegisterType::ESP);
    }

    // ========================================
    // CALL near (0xE8) Tests
    // ========================================

    public function testCallNearForwardJump(): void
    {
        $this->initStack(0x8000);
        // Set CS to 0 for real mode addressing
        $this->setRegister(RegisterType::CS, 0, 16);

        // CALL +0x10 from offset 0
        // After reading opcode (1) + dword displacement (4), IP = 5
        // Target = 5 + 0x10 = 0x15
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xE8) . chr(0x10) . chr(0x00) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte(); // consume opcode

        $this->call->process($this->runtime, [0xE8]);

        // ESP should be decremented by 4 (32-bit mode)
        $this->assertSame(0x7FFC, $this->getStackPointer());
        // Return address (5) should be on stack
        $this->assertSame(5, $this->readMemory(0x7FFC, 32));
        // IP should be at target (0x15)
        $this->assertSame(0x15, $this->memoryStream->offset());
    }

    public function testCallNearBackwardJump(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // CALL -0x10 from offset 0x100
        // After reading opcode (1) + dword displacement (4), IP = 0x105
        // Target = 0x105 + (-0x10) = 0xF5
        $this->memoryStream->setOffset(0x100);
        // -0x10 in signed 32-bit = 0xFFFFFFF0
        $this->memoryStream->write(chr(0xE8) . chr(0xF0) . chr(0xFF) . chr(0xFF) . chr(0xFF));
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->byte(); // consume opcode

        $this->call->process($this->runtime, [0xE8]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x105, $this->readMemory(0x7FFC, 32));
        $this->assertSame(0xF5, $this->memoryStream->offset());
    }

    public function testCallNearZeroDisplacement(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // CALL +0 (effectively just pushes return address)
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xE8) . chr(0x00) . chr(0x00) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();

        $this->call->process($this->runtime, [0xE8]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(5, $this->readMemory(0x7FFC, 32)); // return address
        $this->assertSame(5, $this->memoryStream->offset()); // target = next instruction
    }

    // ========================================
    // RET near (0xC3) Tests
    // ========================================

    public function testRetNear(): void
    {
        $this->initStack(0x7FFC);
        $this->setRegister(RegisterType::CS, 0, 16);
        // Push return address 0x1234 on stack
        $this->writeMemory(0x7FFC, 0x1234, 32);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xC3));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();

        $this->ret->process($this->runtime, [0xC3]);

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0x1234, $this->memoryStream->offset());
    }

    public function testRetNearWithImmediate(): void
    {
        $this->initStack(0x7FFC);
        $this->setRegister(RegisterType::CS, 0, 16);
        // Push return address 0x5678 on stack
        $this->writeMemory(0x7FFC, 0x5678, 32);

        // RET 8 - pop return address then add 8 to ESP
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xC2) . chr(0x08) . chr(0x00)); // RET imm16=8
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();

        $this->ret->process($this->runtime, [0xC2]);

        // ESP = 0x7FFC + 4 (pop) + 8 (imm) = 0x8008
        $this->assertSame(0x8008, $this->getStackPointer());
        $this->assertSame(0x5678, $this->memoryStream->offset());
    }

    // ========================================
    // CALL/RET Roundtrip Tests
    // ========================================

    public function testCallRetRoundtrip(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // CALL +0x20 from offset 0x100
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->write(chr(0xE8) . chr(0x20) . chr(0x00) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->byte();

        $this->call->process($this->runtime, [0xE8]);

        // Verify CALL worked
        $returnAddress = 0x105; // 0x100 + 5 (opcode + dword)
        $targetAddress = 0x125; // 0x105 + 0x20
        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame($returnAddress, $this->readMemory(0x7FFC, 32));
        $this->assertSame($targetAddress, $this->memoryStream->offset());

        // Now execute RET to return
        $this->memoryStream->setOffset($targetAddress);
        $this->memoryStream->write(chr(0xC3));
        $this->memoryStream->setOffset($targetAddress);
        $this->memoryStream->byte();

        $this->ret->process($this->runtime, [0xC3]);

        // Should be back at return address
        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame($returnAddress, $this->memoryStream->offset());
    }

    public function testNestedCalls(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // First CALL from 0x100 to 0x200
        $this->memoryStream->setOffset(0x100);
        // displacement = 0x200 - 0x105 = 0xFB
        $this->memoryStream->write(chr(0xE8) . chr(0xFB) . chr(0x00) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0x100);
        $this->memoryStream->byte();
        $this->call->process($this->runtime, [0xE8]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x105, $this->readMemory(0x7FFC, 32));
        $this->assertSame(0x200, $this->memoryStream->offset());

        // Second CALL from 0x200 to 0x300
        $this->memoryStream->setOffset(0x200);
        // displacement = 0x300 - 0x205 = 0xFB
        $this->memoryStream->write(chr(0xE8) . chr(0xFB) . chr(0x00) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0x200);
        $this->memoryStream->byte();
        $this->call->process($this->runtime, [0xE8]);

        $this->assertSame(0x7FF8, $this->getStackPointer());
        $this->assertSame(0x205, $this->readMemory(0x7FF8, 32)); // second return address
        $this->assertSame(0x105, $this->readMemory(0x7FFC, 32)); // first return address
        $this->assertSame(0x300, $this->memoryStream->offset());

        // First RET - should go back to 0x205
        $this->memoryStream->setOffset(0x300);
        $this->memoryStream->write(chr(0xC3));
        $this->memoryStream->setOffset(0x300);
        $this->memoryStream->byte();
        $this->ret->process($this->runtime, [0xC3]);

        $this->assertSame(0x7FFC, $this->getStackPointer());
        $this->assertSame(0x205, $this->memoryStream->offset());

        // Second RET - should go back to 0x105
        $this->memoryStream->setOffset(0x205);
        $this->memoryStream->write(chr(0xC3));
        $this->memoryStream->setOffset(0x205);
        $this->memoryStream->byte();
        $this->ret->process($this->runtime, [0xC3]);

        $this->assertSame(0x8000, $this->getStackPointer());
        $this->assertSame(0x105, $this->memoryStream->offset());
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testCallDoesNotAffectFlags(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // Set flags to known state
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xE8) . chr(0x10) . chr(0x00) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->call->process($this->runtime, [0xE8]);

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    public function testRetDoesNotAffectFlags(): void
    {
        $this->initStack(0x7FFC);
        $this->setRegister(RegisterType::CS, 0, 16);
        $this->writeMemory(0x7FFC, 0x1234, 32);

        // Set flags to known state
        $this->setCarryFlag(true);
        $this->memoryAccessor->setZeroFlag(true);

        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xC3));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();
        $this->ret->process($this->runtime, [0xC3]);

        // Flags should remain unchanged
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
    }

    public function testRetImmLargeValue(): void
    {
        $this->initStack(0x7FFC);
        $this->setRegister(RegisterType::CS, 0, 16);
        $this->writeMemory(0x7FFC, 0xABCD, 32);

        // RET 0x100 - pop return address then add 256 to ESP
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xC2) . chr(0x00) . chr(0x01)); // RET imm16=256
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();

        $this->ret->process($this->runtime, [0xC2]);

        // ESP = 0x7FFC + 4 (pop) + 256 = 0x8100
        $this->assertSame(0x8100, $this->getStackPointer());
        $this->assertSame(0xABCD, $this->memoryStream->offset());
    }

    public function testCallWithLargePositiveDisplacement(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // CALL +0x1000 from offset 0
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(chr(0xE8) . chr(0x00) . chr(0x10) . chr(0x00) . chr(0x00));
        $this->memoryStream->setOffset(0);
        $this->memoryStream->byte();

        $this->call->process($this->runtime, [0xE8]);

        // Target = 5 + 0x1000 = 0x1005
        $this->assertSame(0x1005, $this->memoryStream->offset());
        $this->assertSame(5, $this->readMemory(0x7FFC, 32));
    }

    public function testCallWithLargeNegativeDisplacement(): void
    {
        $this->initStack(0x8000);
        $this->setRegister(RegisterType::CS, 0, 16);

        // CALL -0x1000 from offset 0x2000
        // Target = 0x2005 + (-0x1000) = 0x1005
        $this->memoryStream->setOffset(0x2000);
        // -0x1000 = 0xFFFFF000
        $this->memoryStream->write(chr(0xE8) . chr(0x00) . chr(0xF0) . chr(0xFF) . chr(0xFF));
        $this->memoryStream->setOffset(0x2000);
        $this->memoryStream->byte();

        $this->call->process($this->runtime, [0xE8]);

        $this->assertSame(0x1005, $this->memoryStream->offset());
        $this->assertSame(0x2005, $this->readMemory(0x7FFC, 32));
    }
}
