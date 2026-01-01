<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Translation Block - a cached sequence of decoded instructions.
 *
 * Similar to QEMU's Translation Block concept:
 * - Single entry point (startIP)
 * - Multiple possible exits (jumps, conditional branches)
 * - Pre-decoded instructions for fast execution
 * - Block chaining for direct jumps between blocks
 */
class TranslationBlock implements TranslationBlockInterface
{
    /**
     * Chained blocks: exitIP => TranslationBlockInterface
     * When this block exits to a known IP, we can jump directly to the next block
     * @var array<int, TranslationBlockInterface>
     */
    private array $chainedBlocks = [];

    /**
     * @param int $startIp Starting IP address of this block
     * @param array<int, array{InstructionInterface, array<int, int>, int}> $instructions
     *        List of [instruction, opcodes, length] tuples
     * @param int $totalLength Total byte length of this block
     */
    public function __construct(
        private readonly int $startIp,
        private readonly array $instructions,
        private readonly int $totalLength,
    ) {
    }

    public function startIp(): int
    {
        return $this->startIp;
    }

    public function totalLength(): int
    {
        return $this->totalLength;
    }

    /**
     * Chain this block to another block at the given exit IP.
     */
    public function chainTo(int $exitIp, TranslationBlockInterface $nextBlock): void
    {
        $this->chainedBlocks[$exitIp] = $nextBlock;
    }

    /**
     * Get chained block for the given exit IP, if any.
     */
    public function getChainedBlock(int $exitIp): ?TranslationBlockInterface
    {
        return $this->chainedBlocks[$exitIp] ?? null;
    }

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
    ): array {
        $memory = $runtime->memory();
        $currentIp = $this->startIp;

        foreach ($this->instructions as [$instruction, $opcodes, $length]) {
            // Update IP to point after this instruction (for relative addressing, etc.)
            $expectedNextIp = $currentIp + $length;
            $memory->setOffset($expectedNextIp);

            if ($beforeInstruction !== null) {
                $beforeInstruction($currentIp, $instruction, $opcodes);
            }

            $status = $instructionRunner !== null
                ? $instructionRunner($instruction, $opcodes)
                : $instruction->process($runtime, $opcodes);

            // Prefix chaining: for instructions that return CONTINUE (e.g. REX/REP),
            // transient overrides must remain active for the next instruction.
            if ($status === ExecutionStatus::CONTINUE) {
                return [$status, $memory->offset()];
            }

            // CRITICAL: Clear transient overrides (like operand size prefix) after each instruction.
            // Without this, prefixes from one instruction bleed into subsequent instructions in the block.
            $runtime->context()->cpu()->clearTransientOverrides();

            // If instruction changed control flow (jump taken), stop block execution
            if ($status !== ExecutionStatus::SUCCESS) {
                return [$status, $memory->offset()];
            }

            // Check if IP was modified by instruction (jump was taken)
            $newIp = $memory->offset();
            if ($newIp !== $expectedNextIp) {
                // Jump was taken, exit block with new IP
                return [ExecutionStatus::SUCCESS, $newIp];
            }

            $currentIp += $length;
        }

        // Fell through to end of block
        return [ExecutionStatus::SUCCESS, $currentIp];
    }

    /**
     * Get the number of instructions in this block.
     */
    public function instructions(): array
    {
        return $this->instructions;
    }

    /**
     * Get the number of instructions in this block.
     */
    public function count(): int
    {
        return count($this->instructions);
    }

    /**
     * Get the expected exit IP (if falling through without jumps).
     */
    public function exitIp(): int
    {
        return $this->startIp + $this->totalLength;
    }

    /**
     * Get the number of chained blocks.
     */
    public function chainCount(): int
    {
        return count($this->chainedBlocks);
    }
}
