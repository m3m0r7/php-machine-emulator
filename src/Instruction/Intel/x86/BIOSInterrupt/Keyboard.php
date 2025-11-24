<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Keyboard implements InterruptInterface
{
    protected bool $isTty;

    public function __construct(protected RuntimeInterface $runtime)
    {
        $runtime->option()->logger()->debug('Reached to keyboard interruption');

        $this->isTty = function_exists('posix_isatty') && posix_isatty(STDIN);

        if ($this->isTty) {
            // NOTE: Disable canonical mode and echo texts
            system('stty -icanon -echo');
        }

        $this->runtime->shutdown(
            // NOTE: Rollback to sane for stty
            fn () => $this->isTty ? system('stty sane') : null
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
