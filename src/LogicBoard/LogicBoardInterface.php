<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard;

use PHPMachineEmulator\LogicBoard\CPU\CPUContextInterface;
use PHPMachineEmulator\LogicBoard\Debug\DebugContextInterface;
use PHPMachineEmulator\LogicBoard\Display\DisplayContextInterface;
use PHPMachineEmulator\LogicBoard\ExternalDevice\ExternalDeviceContextInterface;
use PHPMachineEmulator\LogicBoard\Media\MediaContextInterface;
use PHPMachineEmulator\LogicBoard\Memory\MemoryContextInterface;
use PHPMachineEmulator\LogicBoard\Network\NetworkContextInterface;
use PHPMachineEmulator\LogicBoard\Storage\StorageContextInterface;

interface LogicBoardInterface
{
    public function memory(): MemoryContextInterface;

    public function cpu(): CPUContextInterface;

    public function network(): NetworkContextInterface;

    public function display(): DisplayContextInterface;

    public function storage(): StorageContextInterface;

    public function media(): MediaContextInterface;

    public function externalDevice(): ExternalDeviceContextInterface;

    public function debug(): DebugContextInterface;
}
