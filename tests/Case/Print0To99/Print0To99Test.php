<?php

declare(strict_types=1);

namespace Tests\Case\Print0To99;

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

class Print0To99Test extends TestCase
{
    use CreateApplication;
    use MatchesSnapshots;

    public static function print0To99DataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/Print0To99.o');
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('print0To99DataProvider')]
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
