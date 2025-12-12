<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\ExecutionStatus;

class PatternedInstructionResult
{
    public function __construct(
        private readonly PatternedInstructionResultStatus $status,
        private readonly int $ip,
        private readonly ExecutionStatus $executionStatus = ExecutionStatus::SUCCESS,
    ) {
    }

    public function status(): PatternedInstructionResultStatus
    {
        return $this->status;
    }

    public function ip(): int
    {
        return $this->ip;
    }

    public function executionStatus(): ExecutionStatus
    {
        return $this->executionStatus;
    }

    public function isSuccess(): bool
    {
        return $this->status === PatternedInstructionResultStatus::SUCCESS;
    }

    public function isSkip(): bool
    {
        return $this->status === PatternedInstructionResultStatus::SKIP;
    }

    public static function success(int $ip, ExecutionStatus $executionStatus = ExecutionStatus::SUCCESS): self
    {
        return new self(PatternedInstructionResultStatus::SUCCESS, $ip, $executionStatus);
    }

    public static function skip(int $ip): self
    {
        return new self(PatternedInstructionResultStatus::SKIP, $ip);
    }
}
