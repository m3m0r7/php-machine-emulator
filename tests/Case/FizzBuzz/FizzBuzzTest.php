<?php
declare(strict_types=1);
namespace Tests\Case\FizzBuzz;

use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\Machine;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\FileStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\MatchesSnapshots;
use Tests\CreateApplication;

class FizzBuzzTest extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    #[DataProvider('machineInitialization')]
    public function testPrintHelloWorld(MachineType $machineType, OptionInterface $option)
    {
        $machine = new Machine(
            new FileStream(__DIR__ . '/Fixture/FizzBuzz.o'),
            $option,
        );

        try {
            $machine->runtime($machineType)
                ->start();
        } catch (ExitException|HaltException) {
        }


        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertSame(
            $this->createFizzBuzz(100),
            $output->getBuffer(),
        );
    }

    protected function createFizzBuzz(int $loops): string
    {
        $result = '';
        for ($i = 0; $i < $loops; $i++) {
            $result .= ($i % 15 ? ($i % 5 ? ($i % 3 ? $i : 'Fizz') : 'Buzz') : 'FizzBuzz') . "\r\n";
        }

        return $result . "\r\n";
    }
}
