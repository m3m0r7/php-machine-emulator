<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface InstructionListInterface
{
    public function register(): RegisterInterface;

    /**
     * Find instruction by opcode(s).
     * Returns [instruction, opcodeKey] or throws InvalidOpcodeException.
     *
     * @param int|int[] $opcodes Single opcode or array of opcode bytes
     * @return array{InstructionInterface, int}
     */
    public function findInstruction(int|array $opcodes): array;

    /**
     * Check if a byte sequence matches a multi-byte opcode.
     * Used by Runtime for opcode peeking during fetch.
     *
     * @param int[] $bytes The opcode bytes to check
     * @return bool True if the sequence matches a multi-byte opcode
     */
    public function isMultiByteOpcode(array $bytes): bool;

    /**
     * Get the maximum opcode length registered.
     */
    public function getMaxOpcodeLength(): int;

    public function setRuntime(RuntimeInterface $runtime): void;
    public function runtime(): RuntimeInterface;
}
