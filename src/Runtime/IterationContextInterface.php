<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\ExecutionStatus;

interface IterationContextInterface
{
    /**
     * Set the iteration handler.
     *
     * The handler receives a RuntimeInterface and InstructionExecutorInterface,
     * and is responsible for looping as needed (e.g., REP prefix).
     * It calls executor->execute($runtime) and can inspect executor->lastInstruction(),
     * executor->lastOpcodes(), etc.
     *
     * @param callable(RuntimeInterface, InstructionExecutorInterface): ExecutionStatus $handler
     */
    public function setIterate(callable $handler): void;

    /**
     * Execute iteration with the given instruction executor.
     *
     * If a handler is set, delegates to it. Otherwise, just calls execute() once.
     *
     * @param RuntimeInterface $runtime
     * @param InstructionExecutorInterface $executor
     * @return ExecutionStatus
     */
    public function iterate(RuntimeInterface $runtime, InstructionExecutorInterface $executor): ExecutionStatus;

    /**
     * Check if iteration handler is active.
     */
    public function isActive(): bool;

    /**
     * Clear the handler (reset state for next instruction).
     */
    public function clear(): void;
}
