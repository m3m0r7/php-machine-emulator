<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Architecture;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Video\VideoInterface;

interface ArchitectureProviderInterface
{
    public function observers(): MemoryAccessorObserverCollectionInterface;
    public function video(): VideoInterface;
    public function instructionList(): InstructionListInterface;
}
