<?php
declare(strict_types=1);
namespace Tests\Case\Print0To99;

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

class Print0To99Test extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    #[DataProvider('machineInitialization')]
    public function testPrintHelloWorld(MachineType $machineType, OptionInterface $option)
    {
        $machine = new Machine(
            new BootableFileStream(__DIR__ . '/Fixture/Print0To99.o'),
            $option,
        );

        try {
            $machine->runtime($machineType)
                ->start();
        } catch (ExitException|HaltException) {
        }


        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertSame(implode("\r\n", range(0, 99)) . "\r\n", $output->getBuffer());
    }
}
