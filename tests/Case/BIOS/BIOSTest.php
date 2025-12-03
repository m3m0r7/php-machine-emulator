<?php

declare(strict_types=1);

namespace Tests\Case\BIOS;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\MatchesSnapshots;
use Tests\CreateApplication;
use Tests\Utils\BootableFileStream;

class BIOSTest extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    public static function biosDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/BIOS.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('biosDataProvider')]
    public function testBIOS(MachineInterface $machine, OptionInterface $option)
    {
        try {
            BIOS::start($machine);
        } catch (ExitException) {
        }

        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertMatchesTextSnapshot($output->getBuffer());
    }
}
