<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Disk;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Keyboard;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Video;
use PHPMachineEmulator\Instruction\RegisterType;
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
        $returnIp = $runtime->streamReader()->offset();

        match ($operand) {
            BIOSInterrupt::VIDEO_INTERRUPT => ($this->interruptInstances[Video::class] ??= new Video($runtime))
                ->process($runtime),
            BIOSInterrupt::DISK_INTERRUPT => ($this->interruptInstances[Disk::class] ??= new Disk($runtime))
                ->process($runtime),
            BIOSInterrupt::KEYBOARD_INTERRUPT => ($this->interruptInstances[Keyboard::class] ??= new Keyboard($runtime))
                ->process($runtime),
            default => $this->vectorInterrupt($runtime, $operand, $returnIp),
        };

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Basic IVT-based interrupt handling: push FLAGS, CS, IP then jump to vector.
     */
    private function vectorInterrupt(RuntimeInterface $runtime, BIOSInterrupt $interrupt, int $returnIp): void
    {
        $vectorAddress = ($interrupt->value * 4) & 0xFFFF;

        $offset = $this->readMemory16($runtime, $vectorAddress);
        $segment = $this->readMemory16($runtime, $vectorAddress + 2);

        // Push state (FLAGS, CS, IP)
        $flags =
            ($runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0) |
            ($runtime->memoryAccessor()->shouldParityFlag() ? (1 << 2) : 0) |
            ($runtime->memoryAccessor()->shouldZeroFlag() ? (1 << 6) : 0) |
            ($runtime->memoryAccessor()->shouldSignFlag() ? (1 << 7) : 0) |
            ($runtime->memoryAccessor()->shouldOverflowFlag() ? (1 << 11) : 0) |
            ($runtime->memoryAccessor()->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($runtime->memoryAccessor()->shouldInterruptFlag() ? (1 << 9) : 0);

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ma->push(RegisterType::ESP, $flags);
        $ma->push(RegisterType::ESP, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte());
        $ma->push(RegisterType::ESP, $returnIp);
        $runtime->memoryAccessor()->enableUpdateFlags(true);

        // Clear IF like real INT.
        $runtime->memoryAccessor()->setInterruptFlag(false);

        if (!$runtime->option()->shouldChangeOffset()) {
            return;
        }

        $target = (($segment << 4) + $offset) & 0xFFFFF;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::CS, $segment);

        try {
            $runtime->streamReader()->setOffset($target);
        } catch (StreamReaderException) {
            throw new ExecutionException(
                sprintf('Interrupt vector 0x%02X jumped to invalid address 0x%05X', $interrupt->value, $target),
            );
        }
    }
}
