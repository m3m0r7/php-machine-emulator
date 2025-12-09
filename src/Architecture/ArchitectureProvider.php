<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Architecture;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Video\VideoInterface;

class ArchitectureProvider implements ArchitectureProviderInterface
{
    public function __construct(
        protected VideoInterface $video,
        protected InstructionListInterface $instructionList,
        protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection,
        protected ServiceCollectionInterface $serviceCollection,
        protected InstructionExecutorInterface $instructionExecutor,
    ) {
    }

    public function video(): VideoInterface
    {
        return $this->video;
    }

    public function instructionList(): InstructionListInterface
    {
        return $this->instructionList;
    }

    public function observers(): MemoryAccessorObserverCollectionInterface
    {
        return $this->memoryAccessorObserverCollection;
    }

    public function services(): ServiceCollectionInterface
    {
        return $this->serviceCollection;
    }

    public function instructionExecutor(): InstructionExecutorInterface
    {
        return $this->instructionExecutor;
    }
}
