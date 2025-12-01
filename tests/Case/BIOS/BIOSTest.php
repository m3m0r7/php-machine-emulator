<?php
declare(strict_types=1);
namespace Tests\Case\BIOS;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\OptionInterface;
use Tests\Utils\BootableFileStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\CreateApplication;

class BIOSTest extends TestCase
{
    use CreateApplication;

    #[DataProvider('machineInitialization')]
    public function testBIOS(MachineType $machineType, OptionInterface $option)
    {
        try {
            BIOS::start(
                new BootableFileStream(__DIR__ . '/Fixture/BIOS.o', 0x7C00),
                $machineType,
                $option,
            );
        } catch (ExitException) {
        }

        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertSame("Hello World!\r\n", $output->getBuffer());
    }
}
