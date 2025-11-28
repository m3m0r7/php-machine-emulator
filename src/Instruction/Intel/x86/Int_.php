<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Disk;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Keyboard;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\System;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Video;
use PHPMachineEmulator\Instruction\Intel\x86\DOSInterrupt\Dos;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Int_ implements InstructionInterface
{
    use Instructable;

    private array $interruptInstances = [];
    private array $nestedCount = [];
    private array $inService = [];

    public function opcodes(): array
    {
        return [0xCD];
    }

    /**
     * Raise an interrupt vector programmatically (e.g., CPU fault).
     */
    public function raise(RuntimeInterface $runtime, int $vector, int $returnIp, ?int $errorCode = null): void
    {
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $this->protectedModeInterrupt($runtime, $vector, $returnIp, $errorCode, false);
            return;
        }

        $this->vectorInterrupt($runtime, $vector, $returnIp);
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $vector = $runtime
            ->streamReader()
            ->byte();
        $returnIp = $runtime->streamReader()->offset();

        // $runtime->option()->logger()->debug(sprintf('INT 0x%02X called', $vector));

        $operand = BIOSInterrupt::tryFrom($vector);

        match ($operand) {
            BIOSInterrupt::VIDEO_INTERRUPT => ($this->interruptInstances[Video::class] ??= new Video($runtime))
                ->process($runtime),
            BIOSInterrupt::DISK_INTERRUPT => ($this->interruptInstances[Disk::class] ??= new Disk($runtime))
                ->process($runtime),
            BIOSInterrupt::KEYBOARD_INTERRUPT => ($this->interruptInstances[Keyboard::class] ??= new Keyboard($runtime))
                ->process($runtime),
            BIOSInterrupt::SYSTEM_INTERRUPT => ($this->interruptInstances[System::class] ??= new System())->process($runtime),
            BIOSInterrupt::DOS_INTERRUPT => ($this->interruptInstances[Dos::class] ??= new Dos($this->instructionList))->process($runtime, $opcode),
            default => $this->vectorInterrupt($runtime, $vector, $returnIp),
        };

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Basic IVT-based interrupt handling: push FLAGS, CS, IP then jump to vector.
     */
    private function vectorInterrupt(RuntimeInterface $runtime, int $vector, int $returnIp): void
    {
        // Nesting guard: allow re-entry but cap to avoid runaway.
        $key = $runtime->context()->cpu()->isProtectedMode() ? 'pm' : 'rm';
        $this->nestedCount[$key] = ($this->nestedCount[$key] ?? 0) + 1;
        if ($this->nestedCount[$key] > 8) {
            $this->nestedCount[$key]--;
            return;
        }

        // Protected mode: try IDT lookup
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $this->protectedModeInterrupt($runtime, $vector, $returnIp, null, true);
            $this->nestedCount[$key]--;
            return;
        }

        $vectorAddress = ($vector * 4) & 0xFFFF;

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
        $this->writeCodeSegment($runtime, $segment);

        try {
            $runtime->streamReader()->setOffset($target);
        } catch (StreamReaderException) {
            $this->nestedCount[$key]--;
            throw new ExecutionException(
                sprintf('Interrupt vector 0x%02X jumped to invalid address 0x%05X', $vector, $target),
            );
        }

        $this->nestedCount[$key]--;
    }

    private function protectedModeInterrupt(RuntimeInterface $runtime, int $vector, int $returnIp, ?int $errorCode = null, bool $isSoftware = false): void
    {
        $key = 'pm';
        $this->nestedCount[$key] = ($this->nestedCount[$key] ?? 0) + 1;
        if ($this->nestedCount[$key] > 8) {
            $this->nestedCount[$key]--;
            return;
        }
        $idtr = $runtime->context()->cpu()->idtr();
        $base = $idtr['base'] ?? 0;
        $limit = $idtr['limit'] ?? 0;
        $entryOffset = $vector * 8;

        if ($entryOffset + 7 > $limit) {
            $this->nestedCount[$key]--;
            return;
        }

        $descAddr = $base + $entryOffset;
        $offsetLow = $this->readMemory16($runtime, $descAddr);
        $selector = $this->readMemory16($runtime, $descAddr + 2);
        $typeAttr = $this->readMemory16($runtime, $descAddr + 4);
        $offsetHigh = $this->readMemory16($runtime, $descAddr + 6);

        // Present bit check (bit 15 of typeAttr byte high)
        if (($typeAttr & 0x8000) === 0) {
            return;
        }

        // Gate type must be interrupt/trap gate (0xE/0xF)
        $gateType = ($typeAttr >> 8) & 0x1F;
        $isInterruptGate = $gateType === 0xE;
        $isTrapGate = $gateType === 0xF;
        $isTaskGate = $gateType === 0x5;
        if (!($isInterruptGate || $isTrapGate || $isTaskGate)) {
            $this->nestedCount[$key]--;
            return;
        }

        $gateDpl = ($typeAttr >> 13) & 0x3;
        if ($isSoftware && $runtime->context()->cpu()->cpl() > $gateDpl) {
            throw new FaultException(0x0D, $selector, sprintf('INT %02X DPL check failed', $vector));
        }

        if ($isTaskGate) {
            $this->taskSwitch($runtime, $selector, true, $selector);
            $this->nestedCount[$key]--;
            return;
        }

        $offset = (($offsetHigh & 0xFFFF) << 16) | ($offsetLow & 0xFFFF);

        // Validate target code segment descriptor
        $targetDesc = $this->readSegmentDescriptor($runtime, $selector);
        if ($targetDesc === null || !$targetDesc['present']) {
            $this->nestedCount[$key]--;
            throw new FaultException(0x0B, $selector, sprintf('IDT target selector 0x%04X not present', $selector));
        }
        if (!($targetDesc['executable'] ?? false)) {
            $this->nestedCount[$key]--;
            throw new FaultException(0x0D, $selector, sprintf('IDT target selector 0x%04X is not executable', $selector));
        }

        $operandSize = $runtime->context()->cpu()->operandSize();
        $currentCpl = $runtime->context()->cpu()->cpl();
        $targetCpl = $targetDesc['dpl'];
        $privilegeChange = $targetCpl < $currentCpl;

        $oldSs = $runtime->memoryAccessor()->fetch(RegisterType::SS)->asByte();
        $oldEsp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize($operandSize);
        $mask = $operandSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        if ($privilegeChange) {
            $tss = $runtime->context()->cpu()->taskRegister();
            $tssSelector = $tss['selector'] ?? 0;
            $tssBase = $tss['base'] ?? 0;
            $tssLimit = $tss['limit'] ?? 0;
            if ($tssSelector === 0) {
                $this->nestedCount[$key]--;
                throw new FaultException(0x0A, 0, 'Task register not loaded for privilege change');
            }

            $espOffset = 4 + ($targetCpl * 8);
            $ssOffset = 8 + ($targetCpl * 8);
            if ($tssLimit < $ssOffset + 3) {
                $this->nestedCount[$key]--;
                throw new FaultException(0x0A, $tssSelector, sprintf('TSS too small for ring %d stack', $targetCpl));
            }

            // Use target privilege for paging checks
            $runtime->context()->cpu()->setCpl($targetCpl);
            $runtime->context()->cpu()->setUserMode($targetCpl === 3);

            $newEsp = $this->readMemory32($runtime, $tssBase + $espOffset);
            $newSs = $this->readMemory16($runtime, $tssBase + $ssOffset);

            $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
            $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $operandSize);
        }

        // Push EFLAGS, CS, EIP
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            0x2 | // reserved bit
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0);

        if ($privilegeChange) {
            // On privilege change, old SS/ESP are pushed after loading the new stack.
            $ma->push(RegisterType::ESP, $oldSs, $operandSize);
            $ma->push(RegisterType::ESP, $oldEsp, $operandSize);
        }

        $ma->push(RegisterType::ESP, $flags, $operandSize);
        $ma->push(RegisterType::ESP, $ma->fetch(RegisterType::CS)->asByte(), $operandSize);
        $ma->push(RegisterType::ESP, $returnIp, $operandSize);
        if ($errorCode !== null) {
            $ma->push(RegisterType::ESP, $errorCode & 0xFFFF, $operandSize);
        }

        if ($gateType === 0xE) {
            $runtime->memoryAccessor()->setInterruptFlag(false);
        }

        if (!$runtime->option()->shouldChangeOffset()) {
            $this->nestedCount[$key]--;
            return;
        }

        $linear = $this->linearCodeAddress($runtime, $selector, $offset, $operandSize);
        $this->writeCodeSegment($runtime, $selector);
        $runtime->streamReader()->setOffset($linear);
        $this->nestedCount[$key]--;
    }
}
