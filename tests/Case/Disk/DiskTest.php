<?php

declare(strict_types=1);

namespace Tests\Case\Disk;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\MatchesSnapshots;
use Tests\CreateApplication;
use Tests\Utils\BootableFileStream;

class DiskTest extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    public static function diskDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/Bundle.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('diskDataProvider')]
    public function testPrintHelloWorld(MachineInterface $machine, OptionInterface $option)
    {
        try {
            BIOS::start($machine);
        } catch (ExitException | HaltException) {
        }

        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertMatchesTextSnapshot($output->getBuffer());
    }
}
