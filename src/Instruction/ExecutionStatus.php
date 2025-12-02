<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

enum ExecutionStatus
{
    case SUCCESS;
    case CONTINUE;  // Prefix instruction: don't clear transient overrides
    case FAILED;
    case EXIT;
    case HALT;
}
