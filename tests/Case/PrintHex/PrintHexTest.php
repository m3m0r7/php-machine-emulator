<?php

declare(strict_types=1);

namespace Tests\Case\PrintHex;

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

class PrintHexTest extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    public static function printHexDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/PrintHex.o');
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('printHexDataProvider')]
    public function testPrintHelloWorld(MachineInterface $machine, OptionInterface $option)
    {
        try {
            $machine->runtime()->start();
        } catch (ExitException | HaltException) {
        }

        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        // NOTE: 2525 (niko niko) means `laugh laugh` in Japanese.
        //       COO1 is same COOL in English by a leet-speak
        //
        //       The result means `The cool beef is laughing`
        $this->assertMatchesTextSnapshot($output->getBuffer());
    }
}
