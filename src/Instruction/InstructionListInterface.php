<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface InstructionListInterface
{
    public function register(): RegisterInterface;

    /**
     * Find instruction by opcode(s).
     *
     * @param int|int[] $opcodes Single opcode or array of opcode bytes
     * @return InstructionInterface
     */
    public function findInstruction(int|array $opcodes): InstructionInterface;

    /**
     * Get the maximum opcode length registered.
     */
    public function getMaxOpcodeLength(): int;

    public function setRuntime(RuntimeInterface $runtime): void;
    public function runtime(): RuntimeInterface;
}
