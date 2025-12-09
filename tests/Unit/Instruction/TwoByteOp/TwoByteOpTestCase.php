<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorInterface;
use PHPMachineEmulator\Stream\MemoryStream;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;
use Tests\Utils\TestCPUContext;

/**
 * Base test case for two-byte opcode instructions (0x0F prefix).
 */
abstract class TwoByteOpTestCase extends TestCase
{
    protected TestRuntime $runtime;
    protected MemoryStream $memoryStream;
    protected MemoryAccessorInterface $memoryAccessor;
    protected TestCPUContext $cpuContext;
    protected InstructionListInterface $instructionList;

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->memoryStream = $this->runtime->memory();
        $this->memoryAccessor = $this->runtime->memoryAccessor();
        $this->cpuContext = $this->runtime->cpuContext();

        $this->instructionList = $this->createMock(InstructionListInterface::class);
        $register = new Register();
        $this->instructionList->method('register')->willReturn($register);

        // Default to 32-bit protected mode
        $this->runtime->setProtectedMode32();
    }

    /**
     * Create an instance of the instruction being tested.
     */
    abstract protected function createInstruction(): InstructionInterface;

    /**
     * Execute a two-byte opcode instruction.
     *
     * @param int $secondByte The second byte of the opcode (after 0x0F)
     * @param array $operandBytes Additional operand bytes
     */
    protected function executeTwoByteOp(int $secondByte, array $operandBytes = []): void
    {
        // Write operand bytes to memory stream at offset 0
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);

        // Reset offset
        $this->memoryStream->setOffset(0);

        $instruction = $this->createInstruction();
        $opcodeKey = (0x0F << 8) | $secondByte;
        $instruction->process($this->runtime, [$opcodeKey]);
    }

    // ========================================
    // Register Access
    // ========================================

    protected function setRegister(RegisterType $reg, int $value, int $size = 32): void
    {
        $this->runtime->setRegister($reg, $value, $size);
    }

    protected function getRegister(RegisterType $reg, int $size = 32): int
    {
        return $this->runtime->getRegister($reg, $size);
    }

    // ========================================
    // Memory Access
    // ========================================

    protected function writeMemory(int $address, int $value, int $size = 8): void
    {
        $this->runtime->writeMemory($address, $value, $size);
    }

    protected function readMemory(int $address, int $size = 8): int
    {
        return $this->runtime->readMemory($address, $size);
    }

    // ========================================
    // Flag Access
    // ========================================

    protected function setCarryFlag(bool $value): void
    {
        $this->runtime->setCarryFlag($value);
    }

    protected function getCarryFlag(): bool
    {
        return $this->runtime->getCarryFlag();
    }

    protected function setZeroFlag(bool $value): void
    {
        $this->memoryAccessor->setZeroFlag($value);
    }

    protected function getZeroFlag(): bool
    {
        return $this->runtime->getZeroFlag();
    }

    protected function setSignFlag(bool $value): void
    {
        $this->memoryAccessor->setSignFlag($value);
    }

    protected function getSignFlag(): bool
    {
        return $this->runtime->getSignFlag();
    }

    protected function setOverflowFlag(bool $value): void
    {
        $this->memoryAccessor->setOverflowFlag($value);
    }

    protected function getOverflowFlag(): bool
    {
        return $this->runtime->getOverflowFlag();
    }

    // ========================================
    // CPU Mode
    // ========================================

    protected function setProtectedMode(bool $enabled): void
    {
        $this->cpuContext->setProtectedMode($enabled);
    }

    protected function setOperandSize(int $size): void
    {
        $this->cpuContext->setDefaultOperandSize($size);
    }

    protected function setAddressSize(int $size): void
    {
        $this->cpuContext->setDefaultAddressSize($size);
    }

    protected function setCpl(int $cpl): void
    {
        $this->runtime->setCpl($cpl);
    }
}
