<?php

declare(strict_types=1);

namespace Tests\Case\FizzBuzz;

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

class FizzBuzzTest extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    public static function fizzBuzzDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/FizzBuzz.o');
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('fizzBuzzDataProvider')]
    public function testPrintHelloWorld(MachineInterface $machine, OptionInterface $option)
    {
        try {
            $machine->runtime()->start();
        } catch (ExitException | HaltException) {
        }

        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $this->assertMatchesTextSnapshot($output->getBuffer());
    }
}
