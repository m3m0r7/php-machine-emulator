<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

enum PatternedInstructionResultStatus
{
    case SUCCESS;
    case SKIP;
}
