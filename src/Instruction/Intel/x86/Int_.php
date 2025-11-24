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
use PHPMachineEmulator\Instruction\Intel\x86\DOSInterrupt\Dos;
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
            BIOSInterrupt::DOS_INTERRUPT => ($this->interruptInstances[Dos::class] ??= new Dos($this->instructionList))->process($runtime, $opcode),
            default => $this->vectorInterrupt($runtime, $operand, $returnIp),
        };

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Basic IVT-based interrupt handling: push FLAGS, CS, IP then jump to vector.
     */
    private function vectorInterrupt(RuntimeInterface $runtime, BIOSInterrupt $interrupt, int $returnIp): void
    {
        // Protected mode: try IDT lookup
        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $this->protectedModeInterrupt($runtime, $interrupt->value, $returnIp);
            return;
        }

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
        $ma->push(RegisterType::ESP, $flags, 16);
        $ma->push(RegisterType::ESP, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(), 16);
        $ma->push(RegisterType::ESP, $returnIp, 16);
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

    private function protectedModeInterrupt(RuntimeInterface $runtime, int $vector, int $returnIp): void
    {
        $idtr = $runtime->runtimeOption()->context()->idtr();
        $base = $idtr['base'] ?? 0;
        $limit = $idtr['limit'] ?? 0;
        $entryOffset = $vector * 8;

        if ($entryOffset + 7 > $limit) {
            return;
        }

        $descAddr = $base + $entryOffset;
        $offsetLow = $this->readMemory16($runtime, $descAddr);
        $selector = $this->readMemory16($runtime, $descAddr + 2);
        $typeAttr = $this->readMemory16($runtime, $descAddr + 4);
        $offsetHigh = $this->readMemory16($runtime, $descAddr + 6);

        $offset = (($offsetHigh & 0xFFFF) << 16) | ($offsetLow & 0xFFFF);

        $operandSize = $runtime->runtimeOption()->context()->operandSize();

        // Push EFLAGS, CS, EIP
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0);

        $ma->push(RegisterType::ESP, $flags, $operandSize);
        $ma->push(RegisterType::ESP, $ma->fetch(RegisterType::CS)->asByte(), $operandSize);
        $ma->push(RegisterType::ESP, $returnIp, $operandSize);

        $runtime->memoryAccessor()->setInterruptFlag(false);

        if (!$runtime->option()->shouldChangeOffset()) {
            return;
        }

        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::CS, $selector);
        $runtime->streamReader()->setOffset($offset);
    }
}
