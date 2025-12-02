<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface InstructionListInterface
{
    public function register(): RegisterInterface;

    /**
     * Get instruction by single-byte opcode.
     */
    public function getInstructionByOperationCode(int $opcode): InstructionInterface;

    /**
     * Try to match a multi-byte opcode sequence.
     * Returns [instruction, opcodeKey] if found, null otherwise.
     *
     * @param int[] $bytes The opcode bytes to match
     * @return array{InstructionInterface, int}|null
     */
    public function tryMatchMultiByteOpcode(array $bytes): ?array;

    /**
     * Get the maximum opcode length registered.
     */
    public function getMaxOpcodeLength(): int;

    public function setRuntime(RuntimeInterface $runtime): void;
    public function runtime(): RuntimeInterface;
}
