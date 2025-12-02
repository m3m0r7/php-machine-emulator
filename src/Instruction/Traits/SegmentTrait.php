<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\FaultException;
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
     */
    protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = match ($addressSize) {
            64 => 0xFFFFFFFFFFFFFFFF,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $linearMask = $this->linearMask($runtime);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte();
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor !== null && $descriptor['present']) {
                $effOffset = $offset & $offsetMask;
                if ($effOffset > $descriptor['limit']) {
                    throw new FaultException(0x0D, $selector, sprintf('Segment limit exceeded for selector 0x%04X', $selector));
                }
                return ($descriptor['base'] + $effOffset) & $linearMask;
            }
        }

        return ($this->segmentBase($runtime, $segment) + ($offset & $offsetMask)) & $linearMask;
    }

    /**
     * Get the linear address mask based on A20 gate status.
     */
    protected function linearMask(RuntimeInterface $runtime): int
    {
        // In 64-bit mode, linear addresses are 48 bits (canonical)
        if ($runtime->context()->cpu()->isLongMode() && !$runtime->context()->cpu()->isCompatibilityMode()) {
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
            64 => 0xFFFFFFFFFFFFFFFF,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $linearMask = $this->linearMask($runtime);

        if ($runtime->context()->cpu()->isProtectedMode()) {
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
            64 => 0xFFFFFFFFFFFFFFFF,
            32 => 0xFFFFFFFF,
            default => 0xFFFF,
        };
        $linearMask = $this->linearMask($runtime);

        if ($runtime->context()->cpu()->isProtectedMode()) {
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

        if ($offset + 7 > $base + $limit) {
            return null;
        }

        $limitLow = $this->readMemory8($runtime, $offset) | ($this->readMemory8($runtime, $offset + 1) << 8);
        $baseLow = $this->readMemory8($runtime, $offset + 2) | ($this->readMemory8($runtime, $offset + 3) << 8);
        $baseMid = $this->readMemory8($runtime, $offset + 4);
        $access = $this->readMemory8($runtime, $offset + 5);
        $gran = $this->readMemory8($runtime, $offset + 6);
        $baseHigh = $this->readMemory8($runtime, $offset + 7);

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

        return [
            'base' => $baseAddr & 0xFFFFFFFF,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
            'type' => $type,
            'system' => $system,
            'executable' => $executable,
            'dpl' => $dpl,
            'default' => ($gran & 0x40) !== 0 ? 32 : 16,
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
        }
        $defaultSize = $descriptor['default'] ?? ($ctx->isProtectedMode() ? 32 : 16);
        $ctx->setDefaultOperandSize($defaultSize);
        $ctx->setDefaultAddressSize($defaultSize);
        $this->updateCplFromSelector($runtime, $normalized, $overrideCpl, $descriptor);
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

    // Abstract methods that must be implemented by using class/trait
    abstract protected function readMemory8(RuntimeInterface $runtime, int $address): int;
}
