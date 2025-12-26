<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

interface PatternedInstructionInterface
{
    /**
     * Try to compile a pattern at the given IP.
     *
     * @param int $ip The instruction pointer
     * @param array<int> $bytes The instruction bytes to analyze
     * @return (callable(RuntimeInterface): PatternedInstructionResult)|null Returns a callable that executes the pattern, or null if no pattern found
     */
    public function tryCompile(int $ip, array $bytes): ?callable;

    /**
     * Get the pattern name for logging/debugging purposes.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Get the priority of this pattern (higher priority patterns are checked first).
     *
     * @return int
     */
    public function priority(): int;
}
