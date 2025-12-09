<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Architecture;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoInterface;

interface ArchitectureProviderInterface
{
    public function observers(): MemoryAccessorObserverCollectionInterface;
    public function video(): VideoInterface;
    public function instructionList(): InstructionListInterface;
    public function instructionExecutor(): InstructionExecutorInterface;
    public function services(): ServiceCollectionInterface;
}
