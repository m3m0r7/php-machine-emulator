<?php

declare(strict_types=1);

namespace Tests;

use PHPMachineEmulator\ArchitectureType;
use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Display\Writer\BufferScreenWriterFactory;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\IO\StdIn;
use PHPMachineEmulator\LogicBoard\CPU\CPUContext;
use PHPMachineEmulator\LogicBoard\Display\DisplayContext;
use PHPMachineEmulator\LogicBoard\ExternalDevice\ExternalDeviceContext;
use PHPMachineEmulator\LogicBoard\LogicBoard;
use PHPMachineEmulator\LogicBoard\LogicBoardInterface;
use PHPMachineEmulator\LogicBoard\Media\MediaContext;
use PHPMachineEmulator\LogicBoard\Media\MediaInfo;
use PHPMachineEmulator\LogicBoard\Memory\MemoryContext;
use PHPMachineEmulator\LogicBoard\Network\NetworkContext;
use PHPMachineEmulator\LogicBoard\Storage\StorageContext;
use PHPMachineEmulator\LogicBoard\Storage\StorageInfo;
use PHPMachineEmulator\Machine;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\Option;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use Tests\Utils\EmulatedKeyboardStream;

trait CreateApplication
{
    public static function machineInitialization(): array
    {
        return [
            [self::createOption()],
        ];
    }

    public static function machineInitializationWithMachine(BootableStreamInterface $bootStream, ArchitectureType $architectureType = ArchitectureType::Intel_x86): array
    {
        $option = self::createOption();
        return [
            [self::createMachine($bootStream, $option, BootType::BOOT_SIGNATURE, $architectureType), $option],
        ];
    }

    protected static function createOption(): OptionInterface
    {
        return new Option(
            IO: new IO(
                input: new StdIn(new EmulatedKeyboardStream("Hello World!\r")),
                output: new Buffer(),
                errorOutput: new Buffer(),
            ),
        );
    }

    protected static function createMachine(
        BootableStreamInterface $bootStream,
        OptionInterface $option,
        BootType $bootType = BootType::BOOT_SIGNATURE,
        ArchitectureType $architectureType = ArchitectureType::Intel_x86,
    ): MachineInterface {
        return new Machine(
            self::createLogicBoard($bootStream, $bootType, $architectureType),
            $option,
        );
    }

    protected static function createLogicBoard(
        BootableStreamInterface $bootStream,
        BootType $bootType = BootType::BOOT_SIGNATURE,
        ArchitectureType $architectureType = ArchitectureType::Intel_x86,
    ): LogicBoardInterface {
        return new LogicBoard(
            memoryContext: new MemoryContext(),
            cpuContext: new CPUContext(architectureType: $architectureType),
            networkContext: new NetworkContext(),
            displayContext: new DisplayContext(new BufferScreenWriterFactory()),
            storageContext: new StorageContext(new StorageInfo(0x10000)),
            mediaContext: new MediaContext(new MediaInfo($bootStream, $bootType)),
            externalDeviceContext: new ExternalDeviceContext(),
        );
    }
}
