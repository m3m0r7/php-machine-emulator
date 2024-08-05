<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Architecture;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Video\VideoInterface;

class ArchitectureProvider implements ArchitectureProviderInterface
{
    public function __construct(protected VideoInterface $video, protected InstructionListInterface $instructionList, protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection)
    {}

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
}
