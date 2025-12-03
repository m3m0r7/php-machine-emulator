<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard;

use PHPMachineEmulator\LogicBoard\CPU\CPUContextInterface;
use PHPMachineEmulator\LogicBoard\Display\DisplayContextInterface;
use PHPMachineEmulator\LogicBoard\ExternalDevice\ExternalDeviceContextInterface;
use PHPMachineEmulator\LogicBoard\Media\MediaContextInterface;
use PHPMachineEmulator\LogicBoard\Memory\MemoryContextInterface;
use PHPMachineEmulator\LogicBoard\Network\NetworkContextInterface;
use PHPMachineEmulator\LogicBoard\Storage\StorageContextInterface;

class LogicBoard implements LogicBoardInterface
{
    public function __construct(
        protected MemoryContextInterface $memoryContext,
        protected CPUContextInterface $cpuContext,
        protected NetworkContextInterface $networkContext,
        protected DisplayContextInterface $displayContext,
        protected StorageContextInterface $storageContext,
        protected MediaContextInterface $mediaContext,
        protected ExternalDeviceContextInterface $externalDeviceContext,
    ) {
    }

    public function memory(): MemoryContextInterface
    {
        return $this->memoryContext;
    }

    public function cpu(): CPUContextInterface
    {
        return $this->cpuContext;
    }

    public function network(): NetworkContextInterface
    {
        return $this->networkContext;
    }

    public function display(): DisplayContextInterface
    {
        return $this->displayContext;
    }

    public function storage(): StorageContextInterface
    {
        return $this->storageContext;
    }

    public function media(): MediaContextInterface
    {
        return $this->mediaContext;
    }

    public function externalDevice(): ExternalDeviceContextInterface
    {
        return $this->externalDeviceContext;
    }
}
