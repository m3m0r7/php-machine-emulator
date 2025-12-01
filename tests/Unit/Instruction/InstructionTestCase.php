<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorInterface;
use PHPMachineEmulator\Stream\MemoryStream;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;
use Tests\Utils\TestCPUContext;

abstract class InstructionTestCase extends TestCase
{
    protected TestRuntime $runtime;
    protected MemoryStream $memoryStream;
    protected MemoryAccessorInterface $memoryAccessor;
    protected TestCPUContext $cpuContext;

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->memoryStream = $this->runtime->memory();
        $this->memoryAccessor = $this->runtime->memoryAccessor();
        $this->cpuContext = $this->runtime->cpuContext();

        // Default to 32-bit protected mode
        $this->runtime->setProtectedMode32();
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

    protected function getZeroFlag(): bool
    {
        return $this->runtime->getZeroFlag();
    }

    protected function getSignFlag(): bool
    {
        return $this->runtime->getSignFlag();
    }

    protected function getOverflowFlag(): bool
    {
        return $this->runtime->getOverflowFlag();
    }

    protected function setDirectionFlag(bool $value): void
    {
        $this->runtime->setDirectionFlag($value);
    }

    protected function getDirectionFlag(): bool
    {
        return $this->runtime->getDirectionFlag();
    }

    protected function setInterruptFlag(bool $value): void
    {
        $this->runtime->setInterruptFlag($value);
    }

    protected function getInterruptFlag(): bool
    {
        return $this->runtime->getInterruptFlag();
    }

    // ========================================
    // CPU Mode Switching (convenient helpers)
    // ========================================

    protected function setRealMode16(): void
    {
        $this->runtime->setRealMode16();
    }

    protected function setRealMode32(): void
    {
        $this->runtime->setRealMode32();
    }

    protected function setProtectedMode16(): void
    {
        $this->runtime->setProtectedMode16();
    }

    protected function setProtectedMode32(): void
    {
        $this->runtime->setProtectedMode32();
    }

    // Individual setters (for backward compatibility)
    protected function setProtectedMode(bool $enabled): void
    {
        if ($enabled) {
            $this->cpuContext->setProtectedMode(true);
        } else {
            $this->cpuContext->setProtectedMode(false);
        }
    }

    protected function isProtectedMode(): bool
    {
        return $this->cpuContext->isProtectedMode();
    }

    protected function setAddressSize(int $size): void
    {
        $this->cpuContext->setDefaultAddressSize($size);
    }

    protected function setOperandSize(int $size): void
    {
        $this->cpuContext->setDefaultOperandSize($size);
    }

    // ========================================
    // Privilege Level
    // ========================================

    protected function setCpl(int $cpl): void
    {
        $this->runtime->setCpl($cpl);
    }

    protected function setIopl(int $iopl): void
    {
        $this->runtime->setIopl($iopl);
    }

    // ========================================
    // Instruction Execution
    // ========================================

    protected function executeBytes(array $bytes): void
    {
        // Write instruction bytes to memory stream at offset 0
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $bytes));
        $this->memoryStream->write($code);

        // Reset offset to start of instruction
        $this->memoryStream->setOffset(0);

        $opcode = $bytes[0];
        $instruction = $this->getInstructionByOpcode($opcode);

        if ($instruction === null) {
            $this->fail("No instruction found for opcode 0x" . sprintf("%02X", $opcode));
        }

        $this->memoryStream->byte(); // consume opcode
        $instruction->process($this->runtime, $opcode);
    }

    abstract protected function getInstructionByOpcode(int $opcode): ?object;
}
