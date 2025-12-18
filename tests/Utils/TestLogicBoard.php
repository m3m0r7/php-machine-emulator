<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Display\Writer\WindowScreenWriterFactory;
use PHPMachineEmulator\LogicBoard\CPU\CPUContext;
use PHPMachineEmulator\LogicBoard\CPU\CPUContextInterface;
use PHPMachineEmulator\LogicBoard\Display\DisplayContext;
use PHPMachineEmulator\LogicBoard\Display\DisplayContextInterface;
use PHPMachineEmulator\LogicBoard\ExternalDevice\ExternalDeviceContext;
use PHPMachineEmulator\LogicBoard\ExternalDevice\ExternalDeviceContextInterface;
use PHPMachineEmulator\LogicBoard\LogicBoardInterface;
use PHPMachineEmulator\LogicBoard\Media\MediaContext;
use PHPMachineEmulator\LogicBoard\Media\MediaContextInterface;
use PHPMachineEmulator\LogicBoard\Media\MediaInfo;
use PHPMachineEmulator\LogicBoard\Memory\MemoryContext;
use PHPMachineEmulator\LogicBoard\Memory\MemoryContextInterface;
use PHPMachineEmulator\LogicBoard\Network\NetworkContext;
use PHPMachineEmulator\LogicBoard\Network\NetworkContextInterface;
use PHPMachineEmulator\LogicBoard\Storage\StorageContext;
use PHPMachineEmulator\LogicBoard\Storage\StorageContextInterface;
use PHPMachineEmulator\LogicBoard\Storage\StorageInfo;
use PHPMachineEmulator\Stream\BootableStreamInterface;

class TestLogicBoard implements LogicBoardInterface
{
    private MemoryContextInterface $memoryContext;
    private CPUContextInterface $cpuContext;
    private NetworkContextInterface $networkContext;
    private DisplayContextInterface $displayContext;
    private StorageContextInterface $storageContext;
    private MediaContextInterface $mediaContext;
    private ExternalDeviceContextInterface $externalDeviceContext;

    public function __construct(?BootableStreamInterface $bootStream = null)
    {
        $this->memoryContext = new MemoryContext();
        $this->cpuContext = new CPUContext();
        $this->networkContext = new NetworkContext();
        $this->displayContext = new DisplayContext(new WindowScreenWriterFactory());
        $this->storageContext = new StorageContext(new StorageInfo(0x10000));
        $this->mediaContext = new MediaContext(new MediaInfo($bootStream ?? new TestBootableStream()));
        $this->externalDeviceContext = new ExternalDeviceContext();
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
