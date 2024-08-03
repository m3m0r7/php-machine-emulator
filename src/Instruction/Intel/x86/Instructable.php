<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\InstructionListInterface;

trait Instructable
{
    public function __construct(protected InstructionListInterface $instructionList)
    {

    }
}
