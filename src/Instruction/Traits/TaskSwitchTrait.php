<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for task switching operations.
 * Handles TSS (Task State Segment) operations and task switching.
 * Used by both x86 and x86_64 instructions.
 */
trait TaskSwitchTrait
{
    /**
     * Get TSS32 field offsets.
     */
    protected function tss32Offsets(): array
    {
        return [
            'backlink' => 0x00,
            'esp0' => 0x04,
            'ss0' => 0x08,
            'esp1' => 0x0C,
            'ss1' => 0x10,
            'esp2' => 0x14,
            'ss2' => 0x18,
            'cr3' => 0x1C,
            'eip' => 0x20,
            'eflags' => 0x24,
            'eax' => 0x28,
            'ecx' => 0x2C,
            'edx' => 0x30,
            'ebx' => 0x34,
            'esp' => 0x38,
            'ebp' => 0x3C,
            'esi' => 0x40,
            'edi' => 0x44,
            'es' => 0x48,
            'cs' => 0x4C,
            'ss' => 0x50,
            'ds' => 0x54,
            'fs' => 0x58,
            'gs' => 0x5C,
            'ldtr' => 0x60,
            'iomap' => 0x66,
        ];
    }

    /**
     * Read a call gate descriptor.
     */
    protected function readCallGateDescriptor(RuntimeInterface $runtime, int $selector): ?array
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $runtime->context()->cpu()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
        } else {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
        }

        $index = ($selector >> 3) & 0x1FFF;
        $offset = $base + ($index * 8);
        if ($offset + 7 > $base + $limit) {
            return null;
        }

        $offsetLow = $this->readMemory16($runtime, $offset);
        $targetSelector = $this->readMemory16($runtime, $offset + 2);
        $paramCount = $this->readMemory8($runtime, $offset + 4) & 0x1F;
        $access = $this->readMemory8($runtime, $offset + 5);
        $offsetHigh = $this->readMemory16($runtime, $offset + 6);

        $type = $access & 0x1F;
        $present = ($access & 0x80) !== 0;
        $dpl = ($access >> 5) & 0x3;
        $is32 = ($type & 0x8) !== 0;
        $targetOffset = ($offsetLow | ($offsetHigh << 16)) & 0xFFFFFFFF;

        $isTaskGate = $type === 0x5;
        if (!$isTaskGate && !in_array($type, [0x4, 0xC], true)) {
            return null;
        }

        return [
            'type' => $type,
            'present' => $present,
            'dpl' => $dpl,
            'offset' => $targetOffset,
            'selector' => $targetSelector,
            'wordCount' => $paramCount,
            'is32' => $is32,
            'gateSelector' => $selector & 0xFFFF,
            'isTaskGate' => $isTaskGate,
        ];
    }

    /**
     * Call through a call gate.
     */
    protected function callThroughGate(RuntimeInterface $runtime, array $gate, int $returnOffset, int $returnCs, int $opSize, bool $pushReturn = true, bool $copyParams = true): void
    {
        $cpl = $runtime->context()->cpu()->cpl();
        $rpl = $gate['gateSelector'] & 0x3;
        if (max($cpl, $rpl) > $gate['dpl']) {
            throw new FaultException(0x0D, $gate['gateSelector'], sprintf('Call gate 0x%04X privilege check failed', $gate['gateSelector']));
        }

        if (!$gate['present']) {
            throw new FaultException(0x0B, $gate['gateSelector'], sprintf('Call gate 0x%04X not present', $gate['gateSelector']));
        }

        if ($gate['isTaskGate'] ?? false) {
            $this->taskSwitch($runtime, $gate['selector'], true, $gate['gateSelector'], !$pushReturn);
            return;
        }

        $targetSelector = $gate['selector'];
        if (($targetSelector & 0xFFFC) === 0) {
            throw new FaultException(0x0D, $targetSelector, 'Null selector via call gate');
        }

        $targetDesc = $this->resolveCodeDescriptor($runtime, $targetSelector);
        $newCpl = $this->computeCplForTransfer($runtime, $targetSelector, $targetDesc);
        $privilegeChange = $newCpl < $cpl;

        $ma = $runtime->memoryAccessor();
        $oldSs = $ma->fetch(RegisterType::SS)->asByte();
        $oldEsp = $ma->fetch(RegisterType::ESP)->asBytesBySize($opSize);
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $paramSize = $gate['is32'] ? 4 : 2;
        $params = [];

        if ($copyParams && $privilegeChange && $gate['wordCount'] > 0) {
            for ($i = 0; $i < $gate['wordCount']; $i++) {
                $srcOffset = ($oldEsp + ($i * $paramSize)) & 0xFFFFFFFF;
                $srcLinear = $this->segmentOffsetAddress($runtime, RegisterType::SS, $srcOffset);
                $params[] = $paramSize === 4
                    ? $this->readMemory32($runtime, $srcLinear)
                    : $this->readMemory16($runtime, $srcLinear);
            }
        }

        if ($privilegeChange) {
            $tss = $runtime->context()->cpu()->taskRegister();
            $tssSelector = $tss['selector'] ?? 0;
            $tssBase = $tss['base'] ?? 0;
            $tssLimit = $tss['limit'] ?? 0;
            if ($tssSelector === 0) {
                throw new FaultException(0x0A, 0, 'Task register not loaded for call gate privilege change');
            }
            $espOffset = 4 + ($newCpl * 8);
            $ssOffset = 8 + ($newCpl * 8);
            if ($tssLimit < $ssOffset + 3) {
                throw new FaultException(0x0A, $tssSelector, sprintf('TSS too small for ring %d stack', $newCpl));
            }
            $newEsp = $this->readMemory32($runtime, $tssBase + $espOffset);
            $newSs = $this->readMemory16($runtime, $tssBase + $ssOffset);

            $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $opSize);
            $runtime->context()->cpu()->setCpl($newCpl);
            $runtime->context()->cpu()->setUserMode($newCpl === 3);

            // Copy parameters from old stack to new stack (deepest first) when requested.
            if ($copyParams) {
                for ($i = count($params) - 1; $i >= 0; $i--) {
                    $ma->push(RegisterType::ESP, $params[$i], $paramSize === 4 ? 32 : 16);
                }
            }

            // push old SS:ESP on new stack
            $ma->push(RegisterType::ESP, $oldSs, $opSize);
            $ma->push(RegisterType::ESP, $oldEsp, $opSize);
        }

        if ($pushReturn) {
            // push return CS:EIP on current (or switched) stack
            $ma->push(RegisterType::ESP, $returnCs, $opSize);
            $ma->push(RegisterType::ESP, $returnOffset, $opSize);
        }

        $targetOffset = $gate['offset'] & ($gate['is32'] ? 0xFFFFFFFF : 0xFFFF);
        $linearTarget = $this->linearCodeAddress($runtime, $targetSelector, $targetOffset, $opSize);
        $runtime->memory()->setOffset($linearTarget);
        $this->writeCodeSegment($runtime, $targetSelector, $newCpl, $targetDesc);
    }

    /**
     * Perform a task switch.
     */
    protected function taskSwitch(RuntimeInterface $runtime, int $tssSelector, bool $setBusy = true, ?int $gateSelector = null, bool $isJump = false): void
    {
        $oldTr = $runtime->context()->cpu()->taskRegister();
        $oldSelector = $oldTr['selector'] ?? 0;
        $oldBase = $oldTr['base'] ?? 0;
        $oldLimit = $oldTr['limit'] ?? 0;
        $oldTssDesc = null;
        if ($oldSelector !== 0) {
            $oldTssDesc = $this->readSegmentDescriptor($runtime, $oldSelector);
        }

        $newDesc = $this->readSegmentDescriptor($runtime, $tssSelector);
        if ($newDesc === null) {
            throw new FaultException(0x0D, $tssSelector, sprintf('Invalid TSS selector 0x%04X', $tssSelector));
        }
        if (!$newDesc['present']) {
            throw new FaultException(0x0B, $tssSelector, sprintf('TSS selector 0x%04X not present', $tssSelector));
        }
        $type = $newDesc['type'] ?? 0;
        $is32 = in_array($type, [0x9, 0xB], true);
        $validTypes = [0x9, 0xB]; // only 32-bit TSS supported here
        if (!in_array($type, $validTypes, true)) {
            throw new FaultException(0x0D, $tssSelector, sprintf('Selector 0x%04X is not a 32-bit TSS', $tssSelector));
        }
        if (($type === 0x3 || $type === 0xB)) {
            // busy TSS cannot be target except via IRET or JMP to same task
            if ($tssSelector !== $oldSelector) {
                throw new FaultException(0x0D, $tssSelector, sprintf('TSS selector 0x%04X is busy', $tssSelector));
            }
        }

        // Offsets for 32-bit TSS fields
        $tss32 = $this->tss32Offsets();

        // Save old state into current TSS if present (32-bit TSS layout).
        if ($oldSelector !== 0 && $oldTssDesc !== null && ($oldTssDesc['type'] ?? 0) === 0xB) {
            $oldCr3 = $runtime->memoryAccessor()->readControlRegister(3);
            $csSel = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
            $oldEip = $this->codeOffsetFromLinear($runtime, $csSel, $runtime->memory()->offset(), 32);
            $flagsVal = $this->packFlags($runtime);

            // TSS32 layout
            $this->writeMemory32($runtime, $oldBase + $tss32['cr3'], $oldCr3 & 0xFFFFFFFF);
            $this->writeMemory32($runtime, $oldBase + $tss32['eip'], $oldEip & 0xFFFFFFFF);
            $this->writeMemory32($runtime, $oldBase + $tss32['eflags'], $flagsVal);
            $this->writeMemory32($runtime, $oldBase + $tss32['eax'], $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['ecx'], $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['edx'], $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['ebx'], $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['esp'], $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['ebp'], $runtime->memoryAccessor()->fetch(RegisterType::EBP)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['esi'], $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['edi'], $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asBytesBySize(32));
            $this->writeMemory16($runtime, $oldBase + $tss32['es'], $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['cs'], $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['ss'], $runtime->memoryAccessor()->fetch(RegisterType::SS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['ds'], $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['fs'], $runtime->memoryAccessor()->fetch(RegisterType::FS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['gs'], $runtime->memoryAccessor()->fetch(RegisterType::GS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['ldtr'], $runtime->context()->cpu()->ldtr()['selector'] ?? 0);
        }

        // Load new TSS state (basic parts).
        $newBase = $newDesc['base'];
        $ma = $runtime->memoryAccessor();
        $runtime->memoryAccessor()->writeControlRegister(3, $this->readMemory32($runtime, $newBase + $tss32['cr3']));
        $newEip = $this->readMemory32($runtime, $newBase + $tss32['eip']);
        $newEflags = $this->readMemory32($runtime, $newBase + $tss32['eflags']);
        $ma->writeBySize(RegisterType::EAX, $this->readMemory32($runtime, $newBase + $tss32['eax']), 32);
        $ma->writeBySize(RegisterType::ECX, $this->readMemory32($runtime, $newBase + $tss32['ecx']), 32);
        $ma->writeBySize(RegisterType::EDX, $this->readMemory32($runtime, $newBase + $tss32['edx']), 32);
        $ma->writeBySize(RegisterType::EBX, $this->readMemory32($runtime, $newBase + $tss32['ebx']), 32);
        $ma->writeBySize(RegisterType::ESP, $this->readMemory32($runtime, $newBase + $tss32['esp']), 32);
        $ma->writeBySize(RegisterType::EBP, $this->readMemory32($runtime, $newBase + $tss32['ebp']), 32);
        $ma->writeBySize(RegisterType::ESI, $this->readMemory32($runtime, $newBase + $tss32['esi']), 32);
        $ma->writeBySize(RegisterType::EDI, $this->readMemory32($runtime, $newBase + $tss32['edi']), 32);
        $ma->write16Bit(RegisterType::ES, $this->readMemory16($runtime, $newBase + $tss32['es']));
        $ma->write16Bit(RegisterType::CS, $this->readMemory16($runtime, $newBase + $tss32['cs']));
        $ma->write16Bit(RegisterType::SS, $this->readMemory16($runtime, $newBase + $tss32['ss']));
        $ma->write16Bit(RegisterType::DS, $this->readMemory16($runtime, $newBase + $tss32['ds']));
        $ma->write16Bit(RegisterType::FS, $this->readMemory16($runtime, $newBase + $tss32['fs']));
        $ma->write16Bit(RegisterType::GS, $this->readMemory16($runtime, $newBase + $tss32['gs']));

        $runtime->context()->cpu()->setTaskRegister($tssSelector, $newBase, $newDesc['limit']);
        $runtime->context()->cpu()->setCpl($this->readMemory16($runtime, $newBase + $tss32['cs']) & 0x3);
        $runtime->context()->cpu()->setUserMode($runtime->context()->cpu()->cpl() === 3);

        if ($setBusy && ($type === 0x1 || $type === 0x9)) {
            // Mark new TSS busy
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $index = ($tssSelector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);
            $accessAddr = $descAddr + 5;
            $access = $this->readMemory8($runtime, $accessAddr) | 0x02;
            $phys = $this->translateLinearWithMmio($runtime, $accessAddr, true);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize($phys, $access & 0xFF, 8);
        }

        if ($oldTssDesc !== null && ($oldTssDesc['type'] ?? 0) === 0xB) {
            // Clear busy bit of old TSS if it was busy (for task gate switches).
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $index = ($oldSelector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);
            $accessAddr = $descAddr + 5;
            $access = $this->readMemory8($runtime, $accessAddr) & 0xFD;
            $phys = $this->translateLinearWithMmio($runtime, $accessAddr, true);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize($phys, $access & 0xFF, 8);
        }

        if ($gateSelector !== null) {
            // Save backlink
            $backlink = $oldSelector;
            $runtime->memoryAccessor()->write16Bit($newBase + $tss32['backlink'], $backlink & 0xFFFF);
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $linearTarget = $this->linearCodeAddress($runtime, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(), $newEip, 32);
            $runtime->memory()->setOffset($linearTarget);
        }

        // EFLAGS loaded from TSS (only low 16 bits honored in 32-bit TSS).
        $this->applyFlags($runtime, $newEflags, 32);
    }

    // Abstract methods that must be implemented by using class/trait
    abstract protected function readSegmentDescriptor(RuntimeInterface $runtime, int $selector): ?array;
    abstract protected function resolveCodeDescriptor(RuntimeInterface $runtime, int $selector): array;
    abstract protected function computeCplForTransfer(RuntimeInterface $runtime, int $selector, array $descriptor): int;
    abstract protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int;
    abstract protected function linearCodeAddress(RuntimeInterface $runtime, int $selector, int $offset, int $opSize): int;
    abstract protected function codeOffsetFromLinear(RuntimeInterface $runtime, int $selector, int $linear, int $opSize): int;
    abstract protected function writeCodeSegment(RuntimeInterface $runtime, int $selector, ?int $overrideCpl = null, ?array $descriptor = null): void;
    abstract protected function packFlags(RuntimeInterface $runtime): int;
    abstract protected function applyFlags(RuntimeInterface $runtime, int $flags, int $size = 32): void;
    abstract protected function translateLinearWithMmio(RuntimeInterface $runtime, int $linear, bool $isWrite = false): int;
    abstract protected function readMemory8(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory16(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory32(RuntimeInterface $runtime, int $address): int;
    abstract protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void;
    abstract protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void;
}
