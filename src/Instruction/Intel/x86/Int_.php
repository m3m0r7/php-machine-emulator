<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Exception\NotImplementedException;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Disk;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Keyboard;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\MemorySize;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\System;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Timer;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay;
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
        return $this->applyPrefixes([0xCD]);
    }

    /**
     * Raise an interrupt vector programmatically (e.g., CPU fault).
     */
    public function raise(RuntimeInterface $runtime, int $vector, int $returnIp, ?int $errorCode = null): void
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $isProtected = $runtime->context()->cpu()->isProtectedMode();
        // $returnIp is a linear address (the code stream offset). Convert to an offset
        // relative to the current CS before pushing it for IRET/IRETD.
        $returnOffset = $this->codeOffsetFromLinear($runtime, $cs, $returnIp, $opSize);

        if ($isProtected) {
            $this->protectedModeInterrupt($runtime, $vector, $returnOffset, $errorCode, false);
            return;
        }

        $this->vectorInterrupt($runtime, $vector, $returnOffset);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $vector = $runtime
            ->memory()
            ->byte();

        // INT 11h (Equipment List) is used during DOS boot to detect hardware.
        // Provide a minimal BIOS implementation when IVT still points to ROM.
        if ($vector === 0x11) {
            $vectorAddress = ($vector * 4) & 0xFFFF;
            $ivtSegment = $this->readMemory16($runtime, $vectorAddress + 2);
            if ($ivtSegment !== 0xF000) {
                return $this->handleUnknownInterrupt($runtime, $vector);
            }
            $equipFlags = $this->readMemory16($runtime, 0x410);
            $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $equipFlags, 16);
            $runtime->memoryAccessor()->setCarryFlag(false);
            return ExecutionStatus::SUCCESS;
        }

        // INT 14h (Serial) is probed during DOS boot based on equipment flags.
        // We do not emulate UART hardware yet, so report "port not present/timeout"
        // to keep DOS from calling into uninitialized driver vectors.
        if ($vector === 0x14) {
            $ma = $runtime->memoryAccessor();
            // Set AH=0x80 (timeout/error), AL=0, CF=1.
            $ma->writeToHighBit(RegisterType::EAX, 0x80);
            $ma->writeToLowBit(RegisterType::EAX, 0x00);
            $ma->setCarryFlag(true);
            return ExecutionStatus::SUCCESS;
        }

        $operand = BIOSInterrupt::tryFrom($vector);

        // INT 15h AH=C0 ("Get System Configuration Parameters") is polled during DOS boot.
        // Some DOS/BIOS implementations hook INT 15h early and then chain to the ROM handler.
        // Our ROM handler lives in PHP, so if the vector is hooked we can safely handle AH=C0
        // directly here to avoid getting stuck in broken chain stubs.
        if ($operand === BIOSInterrupt::SYSTEM_INTERRUPT) {
            $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(16);
            $ah = ($ax >> 8) & 0xFF;
            if ($ah === 0xC0) {
                ($this->interruptInstances[System::class] ??= new System())->process($runtime);
                return ExecutionStatus::SUCCESS;
            }
        }

        // If a BIOS interrupt vector has been hooked by software (IVT no longer points
        // into the BIOS ROM segment), respect the hook and delegate via IVT.
        // This is critical once DOS/drivers install their own handlers (e.g. INT 13h).
        if ($operand !== null
            && $operand !== BIOSInterrupt::DOS_TERMINATE_INTERRUPT
            && $operand !== BIOSInterrupt::DOS_INTERRUPT
        ) {
            $vectorAddress = ($vector * 4) & 0xFFFF;
            $ivtSegment = $this->readMemory16($runtime, $vectorAddress + 2);
            if ($ivtSegment !== 0xF000) {
                return $this->handleUnknownInterrupt($runtime, $vector);
            }
        }

        match ($operand) {
            BIOSInterrupt::TIMER_INTERRUPT => ($this->interruptInstances[Timer::class] ??= new Timer())
                ->process($runtime),
            BIOSInterrupt::VIDEO_INTERRUPT => ($this->interruptInstances[Video::class] ??= new Video($runtime))
                ->process($runtime),
            BIOSInterrupt::MEMORY_SIZE_INTERRUPT => ($this->interruptInstances[MemorySize::class] ??= new MemorySize())
                ->process($runtime),
            BIOSInterrupt::DISK_INTERRUPT => ($this->interruptInstances[Disk::class] ??= new Disk($runtime))
                ->process($runtime),
            BIOSInterrupt::KEYBOARD_INTERRUPT => ($this->interruptInstances[Keyboard::class] ??= new Keyboard($runtime))
                ->process($runtime),
            BIOSInterrupt::TIME_OF_DAY_INTERRUPT => ($this->interruptInstances[TimeOfDay::class] ??= new TimeOfDay())
                ->process($runtime),
            BIOSInterrupt::SYSTEM_INTERRUPT => ($this->interruptInstances[System::class] ??= new System())->process($runtime),
            BIOSInterrupt::COMBOOT_INTERRUPT => throw new NotImplementedException('INT 22h (COMBOOT API) is not implemented'),
            // DOS interrupts are handled below to respect IVT hooks when DOS is loaded.
            BIOSInterrupt::DOS_TERMINATE_INTERRUPT, BIOSInterrupt::DOS_INTERRUPT => null,
            default => $this->handleUnknownInterrupt($runtime, $vector),
        };

        // DOS interrupts (INT 20h/21h):
        // If DOS has hooked these vectors, delegate to IVT-based handling.
        // Otherwise provide minimal built-in DOS services (or terminate).
        if ($operand === BIOSInterrupt::DOS_TERMINATE_INTERRUPT || $operand === BIOSInterrupt::DOS_INTERRUPT) {
            $vectorAddress = ($vector * 4) & 0xFFFF;
            $ivtOffset = $this->readMemory16($runtime, $vectorAddress);
            $ivtSegment = $this->readMemory16($runtime, $vectorAddress + 2);
            $ivtIsDefault = ($ivtSegment === 0xF000 && $ivtOffset === 0xFF53);

            if (!$ivtIsDefault) {
                // DOS is present and has installed its own handler.
                return $this->handleUnknownInterrupt($runtime, $vector);
            }

            if ($operand === BIOSInterrupt::DOS_TERMINATE_INTERRUPT) {
                return ExecutionStatus::EXIT;
            }

            // Minimal DOS services when no handler is installed yet.
            return ($this->interruptInstances[Dos::class] ??= new Dos())->process($runtime, $opcodes);
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Handle unknown interrupt vectors by delegating to IVT-based interrupt handling.
     */
    private function handleUnknownInterrupt(RuntimeInterface $runtime, int $vector): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $returnLinear = $runtime->memory()->offset();
        $returnOffset = $this->codeOffsetFromLinear($runtime, $cs, $returnLinear, $opSize);
        $runtime->option()->logger()->debug(sprintf(
            'INT 0x%02X: Not implemented as BIOS service, delegating to IVT-based interrupt handling (return_linear=0x%05X return_offset=0x%04X CS=0x%04X)',
            $vector,
            $returnLinear,
            $returnOffset,
            $cs,
        ));
        $this->vectorInterrupt($runtime, $vector, $returnOffset);
        return ExecutionStatus::SUCCESS;
    }

    /**
     * Basic IVT-based interrupt handling: push FLAGS, CS, IP then jump to vector.
     */
    private function vectorInterrupt(RuntimeInterface $runtime, int $vector, int $returnOffset): void
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
            $this->protectedModeInterrupt($runtime, $vector, $returnOffset, null, true);
            $this->nestedCount[$key]--;
            return;
        }

        $vectorAddress = ($vector * 4) & 0xFFFF;

        $offset = $this->readMemory16($runtime, $vectorAddress);
        $segment = $this->readMemory16($runtime, $vectorAddress + 2);

        // If IVT entry is not initialized (0:0), skip the interrupt
        // This handles cases where the interrupt handler is not set up
        if ($segment === 0 && $offset === 0) {
            $runtime->option()->logger()->debug(sprintf(
                'INT vector 0x%02X: IVT entry is null [0000:0000], skipping interrupt',
                $vector
            ));
            $this->nestedCount[$key]--;
            return;
        }

        // Check if IVT points to default BIOS handler (F000:FF53 = IRET stub)
        // In this case, we should handle the interrupt directly instead of jumping to IRET
        if ($segment === 0xF000 && $offset === 0xFF53) {
            $handled = $this->handleBiosInterruptDirectly($runtime, $vector);
            if ($handled) {
                $runtime->option()->logger()->debug(sprintf(
                    'INT vector 0x%02X: IVT points to default handler, handled directly',
                    $vector
                ));
                $this->nestedCount[$key]--;
                return;
            }
        }

        // Push state (FLAGS, CS, IP)
        $ma = $runtime->memoryAccessor();
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            0x2 | // reserved bit always set
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldAuxiliaryCarryFlag() ? (1 << 4) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0);

        $ma = $runtime->memoryAccessor();
        $ma->push(RegisterType::ESP, $flags, 16);
        $ma->push(RegisterType::ESP, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(), 16);
        $ma->push(RegisterType::ESP, $returnOffset, 16);

        // Clear IF like real INT.
        $runtime->memoryAccessor()->setInterruptFlag(false);

        if (!$runtime->option()->shouldChangeOffset()) {
            return;
        }

        $target = (($segment << 4) + $offset) & 0xFFFFF;
        $runtime->option()->logger()->debug(sprintf('INT vector 0x%02X: IVT entry [%04X:%04X] -> linear 0x%05X, return=0x%04X', $vector, $segment, $offset, $target, $returnOffset));
        $this->writeCodeSegment($runtime, $segment);

        try {
            $runtime->memory()->setOffset($target);
        } catch (StreamReaderException) {
            $this->nestedCount[$key]--;
            throw new ExecutionException(
                sprintf('Interrupt vector 0x%02X jumped to invalid address 0x%05X', $vector, $target),
            );
        }

        $this->nestedCount[$key]--;
    }

    /**
     * Handle BIOS interrupt directly when IVT points to default IRET handler.
     * This is called when software (like ISOLINUX) executes INT xx and IVT has not been
     * overwritten with a custom handler.
     *
     * @return bool True if interrupt was handled, false otherwise
     */
    private function handleBiosInterruptDirectly(RuntimeInterface $runtime, int $vector): bool
    {
        $operand = BIOSInterrupt::tryFrom($vector);

        if ($operand === null) {
            // Not a known BIOS interrupt, let it fall through to default handling
            return false;
        }

        $runtime->option()->logger()->debug(sprintf(
            'INT 0x%02X: Handling BIOS interrupt directly (IVT points to default handler)',
            $vector
        ));

        match ($operand) {
            BIOSInterrupt::TIMER_INTERRUPT => ($this->interruptInstances[Timer::class] ??= new Timer())
                ->process($runtime),
            BIOSInterrupt::VIDEO_INTERRUPT => ($this->interruptInstances[Video::class] ??= new Video($runtime))
                ->process($runtime),
            BIOSInterrupt::MEMORY_SIZE_INTERRUPT => ($this->interruptInstances[MemorySize::class] ??= new MemorySize())
                ->process($runtime),
            BIOSInterrupt::DISK_INTERRUPT => ($this->interruptInstances[Disk::class] ??= new Disk($runtime))
                ->process($runtime),
            BIOSInterrupt::KEYBOARD_INTERRUPT => ($this->interruptInstances[Keyboard::class] ??= new Keyboard($runtime))
                ->process($runtime),
            BIOSInterrupt::TIME_OF_DAY_INTERRUPT => ($this->interruptInstances[TimeOfDay::class] ??= new TimeOfDay())
                ->process($runtime),
            BIOSInterrupt::SYSTEM_INTERRUPT => ($this->interruptInstances[System::class] ??= new System())->process($runtime),
            BIOSInterrupt::COMBOOT_INTERRUPT => throw new NotImplementedException('INT 22h (COMBOOT API) is not implemented'),
            BIOSInterrupt::DOS_TERMINATE_INTERRUPT, BIOSInterrupt::DOS_INTERRUPT => null,
        };

        return true;
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

            $ma = $runtime->memoryAccessor();
            $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $operandSize);
        }

        // Push EFLAGS, CS, EIP
        $ma = $runtime->memoryAccessor();
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            0x2 | // reserved bit always set
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldAuxiliaryCarryFlag() ? (1 << 4) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0);

        if ($privilegeChange) {
            // On privilege change, old SS/ESP are pushed after loading the new stack.
            $ma->push(RegisterType::ESP, $oldSs, 16);
            $ma->push(RegisterType::ESP, $oldEsp, $operandSize);
        }

        $ma->push(RegisterType::ESP, $flags, $operandSize);
        // CS selector is always 16-bit.
        $ma->push(RegisterType::ESP, $ma->fetch(RegisterType::CS)->asByte(), 16);
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
        $runtime->memory()->setOffset($linear);
        $this->nestedCount[$key]--;
    }
}
