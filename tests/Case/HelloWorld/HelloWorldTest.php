<?php
declare(strict_types=1);
namespace Tests\Case\HelloWorld;

use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\Machine;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\FileStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\CreateApplication;

class HelloWorldTest extends TestCase
{
    use CreateApplication;

    #[DataProvider('machineInitialization')]
    public function testPrintHelloWorld(MachineType $machineType, OptionInterface $option)
    {
        $machine = new Machine(
            new FileStream(__DIR__ . '/Fixture/HelloWorld.o'),
            $option,
        );

        try {
            $machine->runtime($machineType)
                ->start(0x7C00);
        } catch (ExitException|HaltException) {
        }


        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertSame("Hello World!\n", $output->getBuffer());
    }
}
