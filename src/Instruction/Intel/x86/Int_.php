<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Disk;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Keyboard;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Video;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Int_ implements InstructionInterface
{
    use Instructable;

    private array $interruptInstances = [];

    public function opcodes(): array
    {
        return [0xCD];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $operand = BIOSInterrupt::from(
            $runtime
                ->streamReader()
                ->byte()
        );

        match ($operand) {
            BIOSInterrupt::VIDEO_INTERRUPT => ($this->interruptInstances[Video::class] ??= new Video($runtime))
                ->process($runtime),
            BIOSInterrupt::DISK_INTERRUPT => ($this->interruptInstances[Disk::class] ??= new Disk($runtime))
                ->process($runtime),
            BIOSInterrupt::KEYBOARD_INTERRUPT => ($this->interruptInstances[Keyboard::class] ??= new Keyboard($runtime))
                ->process($runtime),
            default => throw new ExecutionException(
                sprintf(
                    'Not implemented interrupt types: 0x%02X',
                    $operand,
                )
            ),
        };

        return ExecutionStatus::SUCCESS;
    }

}
