<?php
declare(strict_types=1);
namespace Tests\Case\PrintHex;

use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\Machine;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\OptionInterface;
use Tests\Utils\BootableFileStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\MatchesSnapshots;
use Tests\CreateApplication;

class PrintHexTest extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    #[DataProvider('machineInitialization')]
    public function testPrintHelloWorld(MachineType $machineType, OptionInterface $option)
    {
        $machine = new Machine(
            new BootableFileStream(__DIR__ . '/Fixture/PrintHex.o'),
            $option,
        );

        try {
            $machine->runtime($machineType)
                ->start();
        } catch (ExitException|HaltException) {
        }


        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        // NOTE: 2525 (niko niko) means `laugh laugh` in Japanese.
        //       COO1 is same COOL in English by a leet-speak
        //
        //       The result means `The cool beef is laughing`
        $this->assertSame("0x25 0x25 0xC0 0x01 0xBE 0xEF ", $output->getBuffer());
    }
}
