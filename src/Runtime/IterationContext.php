<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\ExecutionStatus;

class IterationContext implements IterationContextInterface
{
    /** @var callable|null */
    private $iterateHandler = null;

    public function setIterate(callable $handler): void
    {
        $this->iterateHandler = $handler;
    }

    public function iterate(RuntimeInterface $runtime, InstructionExecutorInterface $executor): ExecutionStatus
    {
        if ($this->iterateHandler === null) {
            return $executor->execute($runtime);
        }

        return ($this->iterateHandler)($runtime, $executor);
    }

    public function isActive(): bool
    {
        return $this->iterateHandler !== null;
    }

    public function clear(): void
    {
        $this->iterateHandler = null;
    }
}
