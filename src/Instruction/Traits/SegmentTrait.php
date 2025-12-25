<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for segment-related operations.
 * Handles segment base calculation, descriptor reading, and protected mode checks.
 * Used by both x86 and x86_64 instructions.
 */
trait SegmentTrait
{
    /**
     * Get the linear base address for a segment.
     */
    protected function segmentBase(RuntimeInterface $runtime, RegisterType $segment): int
    {
        $selector = $runtime->memoryAccessor()->fetch($segment)->asByte();

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                return 0;
            }

            if ($segment === RegisterType::CS) {
                $runtime->context()->cpu()->setCpl($descriptor['dpl']);
                $runtime->context()->cpu()->setUserMode($descriptor['dpl'] === 3);
            }

            return $descriptor['base'];
        }

        return ($selector << 4) & 0xFFFFF;
    }

    /**
     * Calculate linear address from segment:offset.
     *
     * Supports Big Real Mode (Unreal Mode):
     * When in real mode but a segment has a cached descriptor with extended limit
     * (from a previous protected mode load), use that cached descriptor's limit
     * to allow access beyond the normal 64KB real mode limit.
     */
    protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = match ($addressSize) {
            // 0xFFFFFFFFFFFFFFFF overflows to float in PHP 8.4; use -1 for 64-bit mask.
            64 => -1,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $linearMask = $this->linearMask($runtime);
        $selector = $runtime->memoryAccessor()->fetch($segment)->asByte();

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor !== null && $descriptor['present']) {
                $effOffset = $offset & $offsetMask;
                if ($effOffset > $descriptor['limit']) {
                    throw new FaultException(0x0D, $selector, sprintf('Segment limit exceeded for selector 0x%04X', $selector));
                }
                return ($descriptor['base'] + $effOffset) & $linearMask;
            }
        }

        // Big Real Mode (Unreal Mode) support:
        // If we have a cached descriptor (loaded in PM), use that base/limit even in real mode.
        // This models Unreal Mode where segment caches retain 32-bit bases/limits after PE is cleared.
        $cachedDescriptor = $runtime->context()->cpu()->getCachedSegmentDescriptor($segment);
        if ($cachedDescriptor !== null) {
            $effOffset = $offset & $offsetMask;
            $limit = $cachedDescriptor['limit'] ?? $offsetMask;
            if ($effOffset > $limit) {
                // Clamp to 16-bit wraparound if offset exceeds the cached limit
                $effOffset = $offset & 0xFFFF;
            }
            $base = $cachedDescriptor['base'] ?? (($selector << 4) & 0xFFFFF);
            return ($base + $effOffset) & $linearMask;
        }

        return ($this->segmentBase($runtime, $segment) + ($offset & $offsetMask)) & $linearMask;
    }

    /**
     * Get the linear address mask based on A20 gate status.
     */
    protected function linearMask(RuntimeInterface $runtime): int
    {
        // In IA-32e mode (long mode, including compatibility mode), linear addresses are 48 bits (canonical)
        if ($runtime->context()->cpu()->isLongMode()) {
            return 0x0000FFFFFFFFFFFF;
        }
        return $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
    }

    /**
     * Calculate linear code address for CS:offset.
     */
    protected function linearCodeAddress(RuntimeInterface $runtime, int $selector, int $offset, int $opSize): int
    {
        $mask = match ($opSize) {
            // 0xFFFFFFFFFFFFFFFF overflows to float in PHP 8.4; use -1 for 64-bit mask.
            64 => -1,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $linearMask = $this->linearMask($runtime);
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            if ($cpu->isProtectedMode()) {
                $descriptor = $this->readSegmentDescriptor($runtime, $selector);
                if ($descriptor === null || !$descriptor['present']) {
                    throw new FaultException(0x0B, $selector, sprintf('Code segment not present for selector 0x%04X', $selector));
                }
                if (($descriptor['system'] ?? false)) {
                    throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
                }
                if (!($descriptor['executable'] ?? false)) {
                    throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not executable', $selector));
                }
            }
            return ($offset & $mask) & $linearMask;
        }

        if ($cpu->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                throw new FaultException(0x0B, $selector, sprintf('Code segment not present for selector 0x%04X', $selector));
            }
            if (($descriptor['system'] ?? false)) {
                throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
            }
            if (!($descriptor['executable'] ?? false)) {
                throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not executable', $selector));
            }
            if (!($descriptor['system'] ?? false)) {
                $dpl = $descriptor['dpl'];
                $rpl = $selector & 0x3;
                $cpl = $runtime->context()->cpu()->cpl();
                $conforming = ($descriptor['type'] & 0x4) !== 0;
                if ($conforming) {
                    if ($cpl < $dpl) {
                        throw new FaultException(0x0D, $selector, sprintf('Conforming code selector 0x%04X requires CPL >= DPL', $selector));
                    }
                } else {
                    if (max($cpl, $rpl) > $dpl) {
                        throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X privilege check failed', $selector));
                    }
                }
            }
            if (($offset & $mask) > $descriptor['limit']) {
                throw new FaultException(0x0D, $selector, sprintf('EIP exceeds segment limit for selector 0x%04X', $selector));
            }
            return ($descriptor['base'] + ($offset & $mask)) & $linearMask;
        }

        return ((($selector << 4) + ($offset & $mask)) & $linearMask);
    }

    /**
     * Convert linear address back to code offset.
     */
    protected function codeOffsetFromLinear(RuntimeInterface $runtime, int $selector, int $linear, int $opSize): int
    {
        $mask = match ($opSize) {
            // 0xFFFFFFFFFFFFFFFF overflows to float in PHP 8.4; use -1 for 64-bit mask.
            64 => -1,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $linearMask = $this->linearMask($runtime);
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            return ($linear & $linearMask) & $mask;
        }

        if ($cpu->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                throw new FaultException(0x0B, $selector, sprintf('Code segment not present for selector 0x%04X', $selector));
            }
            if (($descriptor['system'] ?? false)) {
                throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
            }
            $offset = ($linear - $descriptor['base']) & 0xFFFFFFFF;
            if ($offset > $descriptor['limit']) {
                throw new FaultException(0x0D, $selector, sprintf('Return offset exceeds segment limit for selector 0x%04X', $selector));
            }
            return $offset & $mask;
        }

        return ($linear - (($selector << 4) & $linearMask)) & $mask;
    }

    /**
     * Read a segment descriptor from GDT or LDT.
     */
    protected function readSegmentDescriptor(RuntimeInterface $runtime, int $selector): ?array
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $runtime->context()->cpu()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            // If LDTR not loaded (selector 0), treat as invalid.
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

        $end = $offset + 7;
        // Some system descriptors are 16 bytes wide in IA-32e mode (e.g., 64-bit TSS/LDT).
        // We conservatively fetch the upper 8 bytes when needed below.
        if ($end > $base + $limit) {
            return null;
        }

        // Read all 8 bytes at once using readMemory64.
        $desc = $this->readMemory64($runtime, $offset);
        $descLow = $desc->low32();
        $descHigh = $desc->high32();

        // Parse descriptor fields from the 64-bit value
        // Bytes 0-1: limit low
        $limitLow = $descLow & 0xFFFF;
        // Bytes 2-3: base low
        $baseLow = ($descLow >> 16) & 0xFFFF;
        // Byte 4: base mid
        $baseMid = $descHigh & 0xFF;
        // Byte 5: access
        $access = ($descHigh >> 8) & 0xFF;
        // Byte 6: granularity + limit high
        $gran = ($descHigh >> 16) & 0xFF;
        // Byte 7: base high
        $baseHigh = ($descHigh >> 24) & 0xFF;

        $limitHigh = $gran & 0x0F;
        $fullLimit = $limitLow | ($limitHigh << 16);
        if (($gran & 0x80) !== 0) {
            $fullLimit = ($fullLimit << 12) | 0xFFF;
        }

        $baseAddr = $baseLow | ($baseMid << 16) | ($baseHigh << 24);
        $present = ($access & 0x80) !== 0;
        $type = $access & 0x1F;
        $system = ($access & 0x10) === 0;
        $executable = ($access & 0x08) !== 0;
        $dpl = ($access >> 5) & 0x3;
        $long = ($gran & 0x20) !== 0;

        $baseOut = $baseAddr & 0xFFFFFFFF;
        // In IA-32e mode, some system descriptors (TSS/LDT) have an additional base[63:32] in bytes 8..11.
        if ($runtime->context()->cpu()->isLongMode() && $system) {
            $systemType = $type & 0x0F;
            $isWideSystemDescriptor = in_array($systemType, [0x2, 0x1, 0x3, 0x9, 0xB], true);
            if ($isWideSystemDescriptor) {
                $end16 = $offset + 15;
                if ($end16 > $base + $limit) {
                    return null;
                }
                $desc2 = $this->readMemory64($runtime, $offset + 8);
                $baseUpper = $desc2->low32();
                $baseOut = ($baseOut & 0xFFFFFFFF) | (($baseUpper & 0xFFFFFFFF) << 32);
            }
        }

        return [
            'base' => $baseOut,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
            'type' => $type,
            'system' => $system,
            'executable' => $executable,
            'dpl' => $dpl,
            'default' => ($gran & 0x40) !== 0 ? 32 : 16,
            'long' => $long,
        ];
    }

    /**
     * Update CPL from selector.
     */
    protected function updateCplFromSelector(RuntimeInterface $runtime, int $selector, ?int $overrideCpl = null, ?array $descriptor = null): void
    {
        $ctx = $runtime->context()->cpu();
        $normalized = $selector & 0xFFFF;

        if ($ctx->isProtectedMode()) {
            $descriptor ??= $this->readSegmentDescriptor($runtime, $normalized);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $newCpl = $overrideCpl ?? $descriptor['dpl'];
                $ctx->setCpl($newCpl);
                $ctx->setUserMode($newCpl === 3);
                return;
            }
        }

        $newCpl = $overrideCpl ?? ($normalized & 0x3);
        $ctx->setCpl($newCpl);
        $ctx->setUserMode($newCpl === 3);
    }

    /**
     * Write code segment and update default sizes.
     */
    protected function writeCodeSegment(RuntimeInterface $runtime, int $selector, ?int $overrideCpl = null, ?array $descriptor = null): void
    {
        $normalized = $selector & 0xFFFF;
        $runtime->memoryAccessor()->write16Bit(RegisterType::CS, $normalized);
        $ctx = $runtime->context()->cpu();
        if ($ctx->isProtectedMode()) {
            $descriptor ??= $this->readSegmentDescriptor($runtime, $normalized);
            // Cache CS hidden descriptor state (base/limit/default size).
            // CS cannot be loaded via MOV/POP, so far control transfers must populate the cache.
            if (is_array($descriptor) && ($descriptor['present'] ?? false)) {
                $ctx->cacheSegmentDescriptor(RegisterType::CS, $descriptor);
            }
        } else {
            // Real mode always defaults to 16-bit code; ignore cached descriptor size
            $descriptor = null;
            // In real mode, loading CS (via far jump/call/ret/iret) resets its hidden cache
            // to real-mode base/limit, disabling Unreal Mode for CS.
            $ctx->cacheSegmentDescriptor(RegisterType::CS, [
                'base' => (($normalized << 4) & 0xFFFFF),
                'limit' => 0xFFFF,
                'present' => true,
                'type' => 0,
                'system' => false,
                'executable' => false,
                'dpl' => 0,
                'default' => 16,
            ]);
        }
        if ($ctx->isLongMode() && $ctx->isProtectedMode()) {
            $is64bitCs = ($descriptor['long'] ?? false) && !($descriptor['system'] ?? false) && ($descriptor['executable'] ?? false);
            if ($is64bitCs) {
                $ctx->setCompatibilityMode(false);
                $ctx->setDefaultOperandSize(32);
                $ctx->setDefaultAddressSize(64);
            } else {
                // IA-32e compatibility mode uses legacy CS.D defaults (16/32).
                $ctx->setCompatibilityMode(true);
                $defaultSize = $descriptor['default'] ?? 32;
                $ctx->setDefaultOperandSize($defaultSize);
                $ctx->setDefaultAddressSize($defaultSize);
            }
        } else {
            $defaultSize = $descriptor['default'] ?? ($ctx->isProtectedMode() ? 32 : 16);
            $ctx->setDefaultOperandSize($defaultSize);
            $ctx->setDefaultAddressSize($defaultSize);
        }
        $this->updateCplFromSelector($runtime, $normalized, $overrideCpl, $descriptor);
    }

    /**
     * Update IA-32e (long mode) state based on CR0/CR4/EFER.
     *
     * This keeps the CPUContext flags (longMode/compatibilityMode) in sync with the architectural
     * activation rules:
     * - IA-32e active when CR0.PE=1, CR0.PG=1, CR4.PAE=1, EFER.LME=1
     * - EFER.LMA is set/cleared by hardware when IA-32e becomes active/inactive
     */
    protected function updateIa32eMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $cpu = $runtime->context()->cpu();

        $cr0 = $ma->readControlRegister(0);
        $cr4 = $ma->readControlRegister(4);
        $efer = $ma->readEfer();

        $pe = ($cr0 & 0x1) !== 0;
        $pg = ($cr0 & 0x80000000) !== 0;
        $pae = ($cr4 & (1 << 5)) !== 0;
        $lme = ($efer & (1 << 8)) !== 0;

        $ia32eActive = $pe && $pg && $pae && $lme;
        $lmaBit = 1 << 10;

        if ($ia32eActive) {
            if (!$cpu->isLongMode()) {
                // Long mode requires protected mode; keep defaults reasonable until CS is (re)loaded.
                $cpu->setLongMode(true);

                if ($runtime->logicBoard()->debug()->stopOnIa32eActive()) {
                    $runtime->option()->logger()->warning(sprintf(
                        'STOP: IA-32e activated at ip=0x%08X CR0=0x%08X CR4=0x%08X EFER=0x%08X CS=0x%04X SS=0x%04X RSP=0x%016X',
                        $runtime->memory()->offset() & 0xFFFFFFFF,
                        $cr0 & 0xFFFFFFFF,
                        $cr4 & 0xFFFFFFFF,
                        $efer & 0xFFFFFFFF,
                        $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF,
                        $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF,
                        $ma->fetch(RegisterType::ESP)->asBytesBySize(64),
                    ));
                    throw new HaltException('Stopped by PHPME_STOP_ON_IA32E_ACTIVE');
                }
            }

            $cs = $ma->fetch(RegisterType::CS)->asByte();
            $desc = $cpu->isProtectedMode() ? $this->readSegmentDescriptor($runtime, $cs) : null;
            $is64bitCs = is_array($desc)
                && ($desc['present'] ?? false)
                && !($desc['system'] ?? false)
                && ($desc['executable'] ?? false)
                && ($desc['long'] ?? false);

            if ($is64bitCs) {
                $cpu->setCompatibilityMode(false);
                $cpu->setDefaultOperandSize(32);
                $cpu->setDefaultAddressSize(64);
            } else {
                $cpu->setCompatibilityMode(true);
                $defaultSize = is_array($desc) ? ($desc['default'] ?? 32) : 32;
                $cpu->setDefaultOperandSize((int) $defaultSize);
                $cpu->setDefaultAddressSize((int) $defaultSize);
            }

            if (($efer & $lmaBit) === 0) {
                $ma->writeEfer($efer | $lmaBit);
            }
            return;
        }

        // Leaving IA-32e mode (best-effort): clear flags and LMA.
        if ($cpu->isLongMode()) {
            $cpu->setCompatibilityMode(false);
            $cpu->setLongMode(false);
        }
        if (($efer & $lmaBit) !== 0) {
            $ma->writeEfer($efer & (~$lmaBit));
        }
    }

    /**
     * Resolve and validate a code segment descriptor.
     */
    protected function resolveCodeDescriptor(RuntimeInterface $runtime, int $selector): array
    {
        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null) {
            throw new FaultException(0x0D, $selector, sprintf('Invalid code selector 0x%04X', $selector));
        }
        if (!($descriptor['present'] ?? false)) {
            throw new FaultException(0x0B, $selector, sprintf('Code selector 0x%04X not present', $selector));
        }
        if ($descriptor['system'] ?? false) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
        }
        if (!($descriptor['executable'] ?? false)) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not executable', $selector));
        }
        return $descriptor;
    }

    /**
     * Compute new CPL for control transfer.
     */
    protected function computeCplForTransfer(RuntimeInterface $runtime, int $selector, array $descriptor): int
    {
        $cpl = $runtime->context()->cpu()->cpl();
        $rpl = $selector & 0x3;
        $dpl = $descriptor['dpl'] ?? 0;
        $conforming = ($descriptor['type'] & 0x4) !== 0;

        if ($conforming) {
            if ($cpl < $dpl) {
                throw new FaultException(0x0D, $selector, sprintf('Conforming selector 0x%04X requires CPL >= DPL', $selector));
            }
            return $cpl;
        }

        if (max($cpl, $rpl) > $dpl) {
            throw new FaultException(0x0D, $selector, sprintf('Non-conforming selector 0x%04X privilege check failed', $selector));
        }

        return $dpl;
    }

    /**
     * Cache the current segment descriptors for all segment registers.
     * This is used when leaving protected mode so that the real-mode
     * execution can continue to use the cached bases/limits (Unreal Mode).
     */
    protected function cacheCurrentSegmentDescriptors(RuntimeInterface $runtime): void
    {
        foreach ([RegisterType::CS, RegisterType::DS, RegisterType::ES, RegisterType::FS, RegisterType::GS, RegisterType::SS] as $seg) {
            $selector = $runtime->memoryAccessor()->fetch($seg)->asByte();
            if ($selector === 0) {
                continue;
            }
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $runtime->context()->cpu()->cacheSegmentDescriptor($seg, $descriptor);
            }
        }
    }

    // Abstract methods that must be implemented by using class/trait
    abstract protected function readMemory8(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory64(RuntimeInterface $runtime, int $address): \PHPMachineEmulator\Util\UInt64;
}
