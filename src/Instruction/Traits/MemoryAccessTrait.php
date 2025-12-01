<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for memory access operations.
 * Provides methods for reading/writing memory at various sizes,
 * paging translation, and physical memory access.
 * Used by both x86 and x86_64 instructions.
 */
trait MemoryAccessTrait
{
    /**
     * Read 8-bit value from linear address.
     */
    protected function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical8($runtime, $physical);
    }

    /**
     * Read 16-bit value from linear address.
     */
    protected function readMemory16(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical16($runtime, $physical);
    }

    /**
     * Read 32-bit value from linear address.
     */
    protected function readMemory32(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical32($runtime, $physical);
    }

    /**
     * Read 64-bit value from linear address.
     */
    protected function readMemory64(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical64($runtime, $physical);
    }

    /**
     * Write 8-bit value to linear address.
     */
    protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void
    {
        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value & 0xFF, 8)) {
            return;
        }
        $runtime->memoryAccessor()->writeRawByte($physical, $value & 0xFF);
    }

    /**
     * Write 16-bit value to linear address.
     */
    protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void
    {
        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value & 0xFFFF, 16)) {
            return;
        }
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
    }

    /**
     * Write 32-bit value to linear address.
     */
    protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void
    {
        // Debug: trace writes near stack area
        if ($address >= 0x7FFC0 && $address <= 0x7FFD0) {
            $runtime->option()->logger()->debug(sprintf(
                'WRITE32 to stack: addr=0x%X value=0x%08X IP=0x%X',
                $address,
                $value & 0xFFFFFFFF,
                $runtime->memory()->offset()
            ));
        }

        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value & 0xFFFFFFFF, 32)) {
            return;
        }
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
        $this->writeMemory8($runtime, $address + 2, ($value >> 16) & 0xFF);
        $this->writeMemory8($runtime, $address + 3, ($value >> 24) & 0xFF);
    }

    /**
     * Write 64-bit value to linear address.
     */
    protected function writeMemory64(RuntimeInterface $runtime, int $address, int $value): void
    {
        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value, 64)) {
            return;
        }
        $this->writeMemory32($runtime, $address, $value & 0xFFFFFFFF);
        $this->writeMemory32($runtime, $address + 4, ($value >> 32) & 0xFFFFFFFF);
    }

    /**
     * Translate linear address to physical address through paging.
     */
    protected function translateLinear(RuntimeInterface $runtime, int $linear, bool $isWrite = false): int
    {
        $mask = $this->linearMask($runtime);
        $linear &= $mask;

        if (!$runtime->context()->cpu()->isPagingEnabled()) {
            return $linear;
        }

        $user = $runtime->context()->cpu()->cpl() === 3;
        $cr4 = $runtime->memoryAccessor()->readControlRegister(4);
        $pse = ($cr4 & (1 << 4)) !== 0;
        $pae = ($cr4 & (1 << 5)) !== 0;

        if ($pae) {
            return $this->translateLinearPae($runtime, $linear, $isWrite, $user);
        }

        return $this->translateLinear32($runtime, $linear, $isWrite, $user, $pse);
    }

    /**
     * 32-bit paging translation.
     */
    private function translateLinear32(RuntimeInterface $runtime, int $linear, bool $isWrite, bool $user, bool $pse): int
    {
        $cr3 = $runtime->memoryAccessor()->readControlRegister(3) & 0xFFFFF000;
        $dirIndex = ($linear >> 22) & 0x3FF;
        $tableIndex = ($linear >> 12) & 0x3FF;
        $offset = $linear & 0xFFF;

        $pdeAddr = ($cr3 + ($dirIndex * 4)) & 0xFFFFFFFF;
        $pde = $this->readPhysical32($runtime, $pdeAddr);
        $presentPde = ($pde & 0x1) !== 0;
        if (!$presentPde) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'Page directory entry not present');
        }
        if (($pde & 0xFFFFFF000) === 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved PDE bits');
        }
        if ($user && (($pde & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
        }
        if ($isWrite && (($pde & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'Page directory entry not writable');
        }

        $is4M = $pse && (($pde & (1 << 7)) !== 0);
        if ($is4M) {
            // 4MB page
            $base = $pde & 0xFFC00000;
            if ($user && (($pde & 0x4) === 0)) {
                $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
                throw new FaultException(0x0E, $err, '4MB PDE not user accessible');
            }
            if ($isWrite && (($pde & 0x2) === 0)) {
                $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
                throw new FaultException(0x0E, $err, '4MB PDE not writable');
            }
            $pde |= 0x20;
            if ($isWrite) {
                $pde |= 0x40;
            }
            $this->writePhysical32($runtime, $pdeAddr, $pde);
            return ($base + ($linear & 0x3FFFFF)) & 0xFFFFFFFF;
        }

        $pteAddr = ($pde & 0xFFFFF000) + ($tableIndex * 4);
        $pte = $this->readPhysical32($runtime, $pteAddr);
        $presentPte = ($pte & 0x1) !== 0;
        if (!$presentPte) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'Page table entry not present');
        }
        if (($pte & 0xFFFFFF000) === 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved PTE bits');
        }
        if ($user && (($pte & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'Page table entry not user accessible');
        }
        if ($isWrite && (($pte & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'Page table entry not writable');
        }

        // Set accessed/dirty bits
        if ($presentPde) {
            $pde |= 0x20;
            $this->writePhysical32($runtime, $pdeAddr, $pde);
        }
        if ($presentPte) {
            $pte |= 0x20;
            if ($isWrite) {
                $pte |= 0x40;
            }
            $this->writePhysical32($runtime, $pteAddr, $pte);
        }

        $phys = ($pte & 0xFFFFF000) + $offset;
        return $phys & 0xFFFFFFFF;
    }

    /**
     * PAE paging translation.
     */
    private function translateLinearPae(RuntimeInterface $runtime, int $linear, bool $isWrite, bool $user): int
    {
        $cr3 = $runtime->memoryAccessor()->readControlRegister(3) & 0xFFFFF000;
        $pdpIndex = ($linear >> 30) & 0x3;
        $dirIndex = ($linear >> 21) & 0x1FF;
        $tableIndex = ($linear >> 12) & 0x1FF;
        $offset = $linear & 0xFFF;

        $pdpteAddr = ($cr3 + ($pdpIndex * 8)) & 0xFFFFFFFF;
        $pdpte = $this->readPhysical64($runtime, $pdpteAddr);
        if (($pdpte & 0x1) === 0) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'PDPT entry not present');
        }
        if (($pdpte & (~0x7FF)) !== 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved bit set in PDPT');
        }
        if ($user && (($pdpte & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'PDPT entry not user accessible');
        }
        if ($isWrite && (($pdpte & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'PDPT entry not writable');
        }

        // Mark PDPT accessed
        $this->writePhysical64($runtime, $pdpteAddr, $pdpte | (1 << 5));

        $pdeAddr = (($pdpte & 0xFFFFFF000) + ($dirIndex * 8)) & 0xFFFFFFFF;
        $pde = $this->readPhysical64($runtime, $pdeAddr);
        if (($pde & 0x1) === 0) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'Page directory entry not present');
        }
        $isLarge = ($pde & (1 << 7)) !== 0;
        if (($pde & (~0x7FF)) !== 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved bit set in PDE');
        }
        if ($user && (($pde & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
        }
        if ($isWrite && (($pde & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'Page directory entry not writable');
        }

        if ($isLarge) {
            $pde |= 0x20;
            if ($isWrite) {
                $pde |= 0x40;
            }
            $this->writePhysical64($runtime, $pdeAddr, $pde);
            $base = $pde & 0xFFE00000;
            $phys = ($base + ($linear & 0x1FFFFF)) & 0xFFFFFFFF;
            return $phys;
        }

        $pteAddr = (($pde & 0xFFFFFF000) + ($tableIndex * 8)) & 0xFFFFFFFF;
        $pte = $this->readPhysical64($runtime, $pteAddr);
        if (($pte & 0x1) === 0) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'Page table entry not present');
        }
        if (($pte & (~0x7FF)) !== 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved bit set in PTE');
        }
        if ($user && (($pte & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'Page table entry not user accessible');
        }
        if ($isWrite && (($pte & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'Page table entry not writable');
        }

        $pde |= 0x20;
        $this->writePhysical64($runtime, $pdeAddr, $pde);
        $pte |= 0x20;
        if ($isWrite) {
            $pte |= 0x40;
        }
        $this->writePhysical64($runtime, $pteAddr, $pte);

        $phys = ($pte & 0xFFFFFF000) + $offset;
        return $phys & 0xFFFFFFFF;
    }

    /**
     * Read 8-bit value from physical address.
     */
    protected function readPhysical8(RuntimeInterface $runtime, int $address): int
    {
        $mmio = $this->readMmio($runtime, $address, 8);
        if ($mmio !== null) {
            return $mmio;
        }

        $value = $runtime->memoryAccessor()->readRawByte($address);
        if ($value !== null) {
            return $value;
        }

        try {
            $memory = $runtime->memory();
            $currentOffset = $memory->offset();
            $memory->setOffset($address);
            $byte = $memory->byte();
            $memory->setOffset($currentOffset);
            return $byte;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Read 16-bit value from physical address.
     */
    protected function readPhysical16(RuntimeInterface $runtime, int $address): int
    {
        $mmio = $this->readMmio($runtime, $address, 16);
        if ($mmio !== null) {
            return $mmio;
        }

        $lo = $this->readPhysical8($runtime, $address);
        $hi = $this->readPhysical8($runtime, $address + 1);
        return ($hi << 8) | $lo;
    }

    /**
     * Read 32-bit value from physical address.
     */
    protected function readPhysical32(RuntimeInterface $runtime, int $address): int
    {
        $mmio = $this->readMmio($runtime, $address, 32);
        if ($mmio !== null) {
            return $mmio;
        }

        $b0 = $this->readPhysical8($runtime, $address);
        $b1 = $this->readPhysical8($runtime, $address + 1);
        $b2 = $this->readPhysical8($runtime, $address + 2);
        $b3 = $this->readPhysical8($runtime, $address + 3);
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    /**
     * Read 64-bit value from physical address.
     */
    protected function readPhysical64(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readPhysical32($runtime, $address);
        $hi = $this->readPhysical32($runtime, $address + 4);
        return ($hi << 32) | $lo;
    }

    /**
     * Write 32-bit value to physical address.
     */
    protected function writePhysical32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $runtime->memoryAccessor()->allocate($address, 4, safe: false);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($address, $value, 32);
    }

    /**
     * Write 64-bit value to physical address.
     */
    protected function writePhysical64(RuntimeInterface $runtime, int $address, int $value): void
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;
        $this->writePhysical32($runtime, $address, $lo);
        $this->writePhysical32($runtime, $address + 4, $hi);
    }

    /**
     * Read from MMIO region if applicable.
     */
    private function readMmio(RuntimeInterface $runtime, int $address, int $width): ?int
    {
        $apic = $runtime->context()->cpu()->apicState();
        if ($address >= 0xFEE00000 && $address < 0xFEE01000) {
            $offset = $address - 0xFEE00000;
            return $apic->readLapic($offset, $width);
        }
        if ($address >= 0xFEC00000 && $address < 0xFEC00020) {
            $offset = $address - 0xFEC00000;
            if ($offset === 0x00) {
                return $apic->readIoapicIndex();
            }
            if ($offset === 0x10) {
                return $apic->readIoapicData();
            }
            return 0;
        }

        return null;
    }

    /**
     * Write to MMIO region if applicable.
     */
    private function writeMmio(RuntimeInterface $runtime, int $address, int $value, int $width): bool
    {
        $apic = $runtime?->context()->cpu()->apicState() ?? null;
        if ($apic === null) {
            return false;
        }

        if ($address >= 0xFEE00000 && $address < 0xFEE01000) {
            $offset = $address - 0xFEE00000;
            $apic->writeLapic($offset, $value, $width);
            return true;
        }

        if ($address >= 0xFEC00000 && $address < 0xFEC00020) {
            $offset = $address - 0xFEC00000;
            if ($offset === 0x00) {
                $apic->writeIoapicIndex($value);
            } elseif ($offset === 0x10) {
                $apic->writeIoapicData($value);
            }
            return true;
        }

        return false;
    }

    // Abstract method that must be implemented by using class/trait
    abstract protected function linearMask(RuntimeInterface $runtime): int;
}
