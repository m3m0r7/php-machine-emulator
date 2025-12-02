<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface InstructionInterface
{
    /**
     * Return the opcodes this instruction handles.
     *
     * Can return:
     * - Single-byte opcodes: [0x90, 0x91, ...]
     * - Multi-byte opcodes: [[0x0F, 0x20], [0x0F, 0x22], ...]
     * - Mixed: [0x90, [0x0F, 0x20], ...]
     *
     * @return array<int|int[]>
     */
    public function opcodes(): array;

    /**
     * Process the instruction.
     *
     * @param RuntimeInterface $runtime
     * @param int $opcode The first opcode byte (for single-byte) or the combined opcode key
     * @return ExecutionStatus
     */
    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus;
}
