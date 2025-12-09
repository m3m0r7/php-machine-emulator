<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollection;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Video\VideoInterface;

class TestArchitectureProvider implements ArchitectureProviderInterface
{
    public function observers(): MemoryAccessorObserverCollectionInterface
    {
        return new MemoryAccessorObserverCollection();
    }

    public function video(): VideoInterface
    {
        return new TestVideo();
    }

    public function instructionList(): InstructionListInterface
    {
        return new TestInstructionList();
    }

    public function instructionExecutor(): InstructionExecutorInterface
    {
        return new TestInstructionExecutor();
    }

    public function services(): ServiceCollectionInterface
    {
        return new TestServiceCollection();
    }
}
