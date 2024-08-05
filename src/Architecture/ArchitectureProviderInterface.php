<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Architecture;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Video\VideoInterface;

interface ArchitectureProviderInterface
{
    public function video(): VideoInterface;
    public function instructionList(): InstructionListInterface;
}
