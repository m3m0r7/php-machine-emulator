<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class TestInstructionExecutor implements InstructionExecutorInterface
{
    private ?InstructionInterface $lastInstruction = null;
    private ?array $lastOpcodes = null;
    private int $lastInstructionPointer = 0;

    public function execute(RuntimeInterface $runtime): ExecutionStatus
    {
        return ExecutionStatus::SUCCESS;
    }

    public function lastInstruction(): ?InstructionInterface
    {
        return $this->lastInstruction;
    }

    public function lastOpcodes(): ?array
    {
        return $this->lastOpcodes;
    }

    public function lastInstructionPointer(): int
    {
        return $this->lastInstructionPointer;
    }

    public function invalidateCaches(): void
    {
        // No caching in test executor
    }

    public function invalidateCachesIfExecutedPageOverlaps(int $start, int $length): void
    {
        // No caching in test executor
    }

    public function instructionCount(): int
    {
        return 0;
    }

    public function getIpSampleReport(int $top = 20): array
    {
        return [
            'every' => 0,
            'instructions' => 0,
            'samples' => 0,
            'unique' => 0,
            'top' => [],
        ];
    }
}
