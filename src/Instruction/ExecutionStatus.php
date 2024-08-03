<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

enum ExecutionStatus
{
    case SUCCESS;
    case FAILED;
    case BREAKPOINT;
}
