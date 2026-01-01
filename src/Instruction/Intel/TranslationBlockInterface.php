<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Interface for Translation Block - a cached sequence of decoded instructions.
 *
 * Similar to QEMU's Translation Block concept:
 * - Single entry point (startIP)
 * - Multiple possible exits (jumps, conditional branches)
 * - Pre-decoded instructions for fast execution
 * - Block chaining for direct jumps between blocks
 */
interface TranslationBlockInterface
{
    /**
     * Get the starting IP address of this block.
     */
    public function startIp(): int;

    /**
     * Get the total byte length of this block.
     */
    public function totalLength(): int;

    /**
     * Chain this block to another block at the given exit IP.
     */
    public function chainTo(int $exitIp, TranslationBlockInterface $nextBlock): void;

    /**
     * Get chained block for the given exit IP, if any.
     */
    public function getChainedBlock(int $exitIp): ?TranslationBlockInterface;

    /**
     * Execute all instructions in this block.
     *
     * @param callable|null $beforeInstruction Callback invoked before each instruction:
     *                                         fn(int $ip, InstructionInterface $instruction, array $opcodes): void
     * @param callable|null $instructionRunner Callback used to execute an instruction instead of calling process()
     *
     * @return array{ExecutionStatus, int} [status, exitIp]
     */
    public function execute(
        RuntimeInterface $runtime,
        ?callable $beforeInstruction = null,
        ?callable $instructionRunner = null,
    ): array;

    /**
     * Get the instructions in this block.
     *
     * @return array<int, array{InstructionInterface, array<int, int>, int}>
     */
    public function instructions(): array;

    /**
     * Get the number of instructions in this block.
     */
    public function count(): int;

    /**
     * Get the expected exit IP (if falling through without jumps).
     */
    public function exitIp(): int;

    /**
     * Get the number of chained blocks.
     */
    public function chainCount(): int;
}
