<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Keyboard implements InterruptInterface
{
    public function __construct(protected RuntimeInterface $runtime)
    {
        $runtime->option()->logger()->debug('Reached to keyboard interruption');

        // NOTE: Disable canonical mode and echo texts
        system('stty -icanon -echo');

        $this->runtime->shutdown(
            // NOTE: Rollback to sane for stty
            fn () => system('stty sane')
        );
    }

    public function process(RuntimeInterface $runtime): void
    {
        $byte = $runtime
            ->option()
            ->IO()
            ->input()
            ->byte();

        // NOTE: Convert the break line (0x0A) to the carriage return (0x0D)
        //       because it is applying duplication breaking lines in using terminal.
        if ($byte === 0x0A) {
            $byte = 0x0D;
        }

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                RegisterType::EAX,
                $byte,
            );
    }
}
