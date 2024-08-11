<?php
declare(strict_types=1);
namespace Tests;

use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\IO\StdIn;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\Option;
use PHPMachineEmulator\OptionInterface;
use Tests\Utils\EmulatedKeyboardStream;

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
                input: new StdIn(new EmulatedKeyboardStream("Hello World!\r")),
                output: new Buffer(),
                errorOutput: new Buffer(),
            ),
        );
    }
}
