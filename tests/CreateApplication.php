<?php
declare(strict_types=1);
namespace Tests;

use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\Option;
use PHPMachineEmulator\OptionInterface;

trait CreateApplication
{
    public static function machineInitialization(): array
    {
        return [
            [MachineType::Intel_x86, self::createOption()],
        ];
    }

    protected static function createOption(): OptionInterface
    {
        return new Option(
            IO: new IO(
                output: new Buffer(),
                errorOutput: new Buffer(),
            ),
        );
    }
}
