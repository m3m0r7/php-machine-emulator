<?php

declare(strict_types=1);

namespace PHPMachineEmulator\BIOS;

use PHPMachineEmulator\Exception\NotImplementedException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Disk;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Keyboard;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\MemorySize;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\System;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Timer;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Video;
use PHPMachineEmulator\Instruction\Intel\x86\DOSInterrupt\Dos;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * PHP BIOS Call - Custom instruction for direct BIOS service invocation.
 *
 * Opcode: 0F FF xx (where xx is the interrupt number)
 *
 * This is a custom 3-byte instruction that directly invokes PHP BIOS handlers
 * WITHOUT going through the IVT. This prevents infinite loops when:
 * 1. Custom interrupt handlers call original BIOS handlers
 * 2. BIOS trampolines need to call PHP handlers
 *
 * Usage in BIOS ROM trampolines:
 *   Instead of: CD 13 CF (INT 13h + IRET)
 *   Use:        0F FF 13 CF (PHPBIOS 13h + IRET)
 *
 * This class contains all BIOS interrupt handling logic and is also called
 * by Int_ when IVT points to default BIOS handlers.
 */
class PHPBIOSCall implements InstructionInterface
{
    use Instructable;

    private array $interruptInstances = [];

    public function opcodes(): array
    {
        return [[0x0F, 0xFF]];
    }

    /**
     * Process the PHPBIOS instruction (0F FF xx).
     */
    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $vector = $runtime->memory()->byte();
        $this->ensureTrampolineFlags($runtime);
        return $this->handleInterrupt($runtime, $vector);
    }

    /**
     * Some bootloaders invoke BIOS services via far return (RETF) into our trampolines,
     * which means no FLAGS word is present on the stack. The trampoline ends with IRET,
     * so synthesize a sane FLAGS word when missing to avoid returning to garbage.
     */
    private function ensureTrampolineFlags(RuntimeInterface $runtime): void
    {
        $cpu = $runtime->context()->cpu();
        if ($cpu->isProtectedMode()) {
            return;
        }

        $ma = $runtime->memoryAccessor();
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize(16) & 0xFFFF;
        $flagsAddr = $this->segmentOffsetAddress($runtime, RegisterType::SS, ($sp + 4) & 0xFFFF);
        $flagsCandidate = $this->readMemory16($runtime, $flagsAddr);

        if (($flagsCandidate & 0x2) !== 0) {
            return;
        }

        // Bit 1 must always be set; keep IF enabled for typical BIOS behavior.
        $this->writeMemory16($runtime, $flagsAddr, 0x0202);
        $runtime->option()->logger()->debug(sprintf(
            'PHPBIOS: synthesized FLAGS at SS:SP+4 (SP=0x%04X)',
            $sp & 0xFFFF
        ));
    }

    /**
     * Handle a BIOS interrupt directly without IVT lookup.
     * This is the core BIOS handling logic used by both:
     * - 0F FF xx instruction (direct call from trampolines)
     * - Int_ when IVT points to default BIOS handlers
     *
     * @return ExecutionStatus The execution status
     */
    public function handleInterrupt(RuntimeInterface $runtime, int $vector): ExecutionStatus
    {
        $operand = BIOSInterrupt::tryFrom($vector);

        if ($operand === null) {
            $runtime->option()->logger()->debug(sprintf(
                'PHPBIOS 0x%02X: Unknown BIOS interrupt, ignored',
                $vector
            ));
            return ExecutionStatus::SUCCESS;
        }

        $runtime->option()->logger()->debug(sprintf(
            'PHPBIOS 0x%02X: Direct PHP BIOS call',
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
            BIOSInterrupt::SYSTEM_INTERRUPT => ($this->interruptInstances[System::class] ??= new System())
                ->process($runtime),
            BIOSInterrupt::COMBOOT_INTERRUPT => throw new NotImplementedException('INT 22h (COMBOOT API) is not implemented'),
            BIOSInterrupt::DOS_TERMINATE_INTERRUPT => null,
            BIOSInterrupt::DOS_INTERRUPT => ($this->interruptInstances[Dos::class] ??= new Dos())
                ->process($runtime, [0xCD]),
        };

        if ($operand === BIOSInterrupt::DOS_TERMINATE_INTERRUPT) {
            return ExecutionStatus::EXIT;
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Check if a given interrupt vector is a known BIOS interrupt.
     */
    public function isKnownBiosInterrupt(int $vector): bool
    {
        return BIOSInterrupt::tryFrom($vector) !== null;
    }
}
