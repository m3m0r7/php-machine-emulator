<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Writer\TerminalScreenWriter;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * Trait for memory access operations.
 * Provides methods for reading/writing memory at various sizes,
 * paging translation, and physical memory access.
 * Uses Rust-backed MemoryAccessor for high performance.
 */
trait MemoryAccessTrait
{
    /**
     * Read 8-bit value from linear address.
     */
    protected function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        [$value, $error] = $ma->readMemory8($address, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            // MMIO - handle in PHP
            $physical = $this->translateLinearWithMmio($runtime, $address, false);
            return $this->readMmio($runtime, $physical, 8) ?? $ma->readPhysical8($physical);
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }

        return $value;
    }

    /**
     * Read 16-bit value from linear address.
     */
    protected function readMemory16(RuntimeInterface $runtime, int $address): int
    {
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        [$value, $error] = $ma->readMemory16($address, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            $physical = $this->translateLinearWithMmio($runtime, $address, false);
            return $this->readMmio($runtime, $physical, 16) ?? $ma->readPhysical16($physical);
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }

        return $value;
    }

    /**
     * Read 32-bit value from linear address.
     */
    protected function readMemory32(RuntimeInterface $runtime, int $address): int
    {
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        [$value, $error] = $ma->readMemory32($address, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            $physical = $this->translateLinearWithMmio($runtime, $address, false);
            return $this->readMmio($runtime, $physical, 32) ?? $ma->readPhysical32($physical);
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }

        return $value;
    }

    /**
     * Read 64-bit value from linear address.
     */
    protected function readMemory64(RuntimeInterface $runtime, int $address): UInt64
    {
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        [$value, $error] = $ma->readMemory64($address, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            $physical = $this->translateLinearWithMmio($runtime, $address, false);
            $mmio = $this->readMmio($runtime, $physical, 64);
            if ($mmio !== null) {
                return UInt64::fromParts($mmio & 0xFFFFFFFF, ($mmio >> 32) & 0xFFFFFFFF);
            }
            $lo = $ma->readPhysical32($physical);
            $hi = $ma->readPhysical32($physical + 4);
            return UInt64::fromParts($lo, $hi);
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }

        return UInt64::fromParts($value & 0xFFFFFFFF, ($value >> 32) & 0xFFFFFFFF);
    }

    /**
     * Best-effort debug hook for unexpected writes into code segments.
     */
    protected function logSuspiciousWrite(RuntimeInterface $runtime, int $address, int $width): void
    {
        $logger = $runtime->option()->logger();
        if (!$logger->isHandling(\Monolog\Level::Debug)) {
            return;
        }

        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $mask = $this->linearMask($runtime);
        $base = (($cs << 4) & $mask);
        $offset = ($address - $base) & 0xFFFFFFFF;
        if ($address < 0x12E00 || $address > 0x13000) {
            return;
        }

        $executor = $runtime->architectureProvider()->instructionExecutor();
        $lastOpcodes = $executor->lastOpcodes();
        $lastOpcodeStr = $lastOpcodes === null
            ? 'n/a'
            : implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $lastOpcodes));
        $lastInstruction = $executor->lastInstruction();
        $lastInstructionName = $lastInstruction === null
            ? 'n/a'
            : (preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($lastInstruction)) ?? 'n/a');
        $logger->debug(sprintf(
            'WRITE into CS region: addr=0x%08X cs=0x%04X off=0x%04X width=%d ip=0x%08X lastIns=%s lastOp=%s',
            $address & 0xFFFFFFFF,
            $cs,
            $offset & 0xFFFF,
            $width,
            $runtime->memory()->offset() & 0xFFFFFFFF,
            $lastInstructionName,
            $lastOpcodeStr
        ));
    }

    /**
     * Write 8-bit value to linear address.
     */
    protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->logSuspiciousWrite($runtime, $address, 8);
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        $error = $ma->writeMemory8($address, $value, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            // MMIO - handle in PHP
            try {
                $physical = $this->translateLinearWithMmio($runtime, $address, true);
                if ($this->writeMmio($runtime, $physical, $value & 0xFF, 8)) {
                    return;
                }
                $ma->writeRawByte($physical, $value & 0xFF);
            } catch (\Throwable) {
                // Address out of bounds - ignore write to unmapped memory
            }
            return;
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }
    }

    /**
     * Write 16-bit value to linear address.
     */
    protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->logSuspiciousWrite($runtime, $address, 16);
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        $error = $ma->writeMemory16($address, $value & 0xFFFF, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            try {
                $physical = $this->translateLinearWithMmio($runtime, $address, true);
                if ($this->writeMmio($runtime, $physical, $value & 0xFFFF, 16)) {
                    return;
                }
                $ma->writePhysical16($physical, $value & 0xFFFF);
            } catch (\Throwable) {
                // Ignore write to unmapped memory
            }
            return;
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }
    }

    /**
     * Write 32-bit value to linear address.
     */
    protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->logSuspiciousWrite($runtime, $address, 32);
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        $error = $ma->writeMemory32($address, $value & 0xFFFFFFFF, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            try {
                $physical = $this->translateLinearWithMmio($runtime, $address, true);
                if ($this->writeMmio($runtime, $physical, $value & 0xFFFFFFFF, 32)) {
                    return;
                }
                $ma->writePhysical32($physical, $value & 0xFFFFFFFF);
            } catch (\Throwable) {
                // Ignore write to unmapped memory
            }
            return;
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }
    }

    /**
     * Write 64-bit value to linear address.
     */
    protected function writeMemory64(RuntimeInterface $runtime, int $address, UInt64|int $value): void
    {
        if ($value instanceof UInt64) {
            // For UInt64, use bulk 32-bit writes
            $this->writeMemory32($runtime, $address, $value->low32());
            $this->writeMemory32($runtime, $address + 4, $value->high32());
            return;
        }

        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        $error = $ma->writeMemory64($address, $value, $isUser, $pagingEnabled, $mask);

        if ($error === 0xFFFFFFFF) {
            try {
                $physical = $this->translateLinearWithMmio($runtime, $address, true);
                if ($this->writeMmio($runtime, $physical, $value, 64)) {
                    return;
                }
                $ma->writePhysical64($physical, $value);
            } catch (\Throwable) {
                // Ignore write to unmapped memory
            }
            return;
        }

        if ($error !== 0) {
            $this->throwPageFault($runtime, $address, $error);
        }
    }

    /**
     * Translate linear address to physical address through paging.
     * Used when MMIO handling is needed.
     */
    protected function translateLinearWithMmio(RuntimeInterface $runtime, int $linear, bool $isWrite = false): int
    {
        $ma = $runtime->memoryAccessor();
        $mask = $this->linearMask($runtime);
        $isUser = $runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $runtime->context()->cpu()->isPagingEnabled();

        [$physical, $error] = $ma->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $mask);

        if ($error !== 0 && $error !== 0xFFFFFFFF) {
            $this->throwPageFault($runtime, $linear, $error);
        }

        return $physical;
    }

    /**
     * Throw a page fault exception from packed error code.
     */
    private function throwPageFault(RuntimeInterface $runtime, int $linear, int $error): void
    {
        $vector = ($error >> 16) & 0xFF;
        $errorCode = $error & 0xFFFF;
        $cpu = $runtime->context()->cpu();

        // CR2 holds the faulting linear address.
        // In IA-32e (64-bit mode), it is a canonical 64-bit address (sign-extended from bit 47).
        // In legacy modes, CR2 is effectively 32-bit.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $masked = $linear & 0x0000FFFFFFFFFFFF;
            $canonical = ($masked & 0x0000800000000000) !== 0
                ? ($masked | (-1 << 48))
                : $masked;
            $runtime->memoryAccessor()->writeControlRegister(2, $canonical);
        } else {
            $runtime->memoryAccessor()->writeControlRegister(2, $linear & 0xFFFFFFFF);
        }
        throw new FaultException($vector, $errorCode, 'Page fault');
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
        return $runtime->memoryAccessor()->readPhysical8($address);
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
        return $runtime->memoryAccessor()->readPhysical16($address);
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
        return $runtime->memoryAccessor()->readPhysical32($address);
    }

    /**
     * Read 64-bit value from physical address.
     */
    protected function readPhysical64(RuntimeInterface $runtime, int $address): UInt64
    {
        // Check for MMIO first
        $mmio = $this->readMmio($runtime, $address, 64);
        if ($mmio !== null) {
            return UInt64::fromParts($mmio & 0xFFFFFFFF, ($mmio >> 32) & 0xFFFFFFFF);
        }
        // Use bulk 64-bit read from Rust
        $value = $runtime->memoryAccessor()->readPhysical64($address);
        return UInt64::fromParts($value & 0xFFFFFFFF, ($value >> 32) & 0xFFFFFFFF);
    }

    /**
     * Write 32-bit value to physical address.
     */
    protected function writePhysical32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $runtime->memoryAccessor()->writePhysical32($address, $value);
    }

    /**
     * Write 64-bit value to physical address.
     */
    protected function writePhysical64(RuntimeInterface $runtime, int $address, UInt64|int $value): void
    {
        if ($value instanceof UInt64) {
            // Convert UInt64 to int for bulk write
            $intValue = $value->low32() | ($value->high32() << 32);
            $runtime->memoryAccessor()->writePhysical64($address, $intValue);
        } else {
            $runtime->memoryAccessor()->writePhysical64($address, $value);
        }
    }

    /**
     * Read from MMIO region if applicable.
     */
    private function readMmio(RuntimeInterface $runtime, int $address, int $width): ?int
    {
        $video = $runtime->context()->devices()->video();
        $lfb = $video->linearFramebufferInfo();
        if ($lfb !== null && $address >= $lfb['base'] && $address < ($lfb['base'] + $lfb['size'])) {
            return $video->linearFramebufferRead($address, $width);
        }

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
        $video = $runtime?->context()->devices()->video() ?? null;
        if ($video !== null) {
            $lfb = $video->linearFramebufferInfo();
            if ($lfb !== null && $address >= $lfb['base'] && $address < ($lfb['base'] + $lfb['size'])) {
                if (!$video->linearFramebufferWrite($address, $value, $width)) {
                    return false;
                }

                // Render 32bpp pixels on aligned 32-bit writes.
                if ($width === 32 && $lfb['bitsPerPixel'] === 32) {
                    $offset = ($address - $lfb['base']) & 0xFFFFFFFF;
                    if (($offset & 0x3) === 0) {
                        $x = intdiv($offset % $lfb['bytesPerScanLine'], 4);
                        $y = intdiv($offset, $lfb['bytesPerScanLine']);

                        if ($x >= 0 && $x < $lfb['width'] && $y >= 0 && $y < $lfb['height']) {
                            $writer = $runtime->context()->screen()->screenWriter();
                            $renderTerminal = $runtime->logicBoard()->debug()->memoryAccess()->renderLfbToTerminal;
                            $shouldRender = !($writer instanceof TerminalScreenWriter)
                                || $renderTerminal;

                            if ($shouldRender) {
                                $b = $value & 0xFF;
                                $g = ($value >> 8) & 0xFF;
                                $r = ($value >> 16) & 0xFF;
                                $writer->dot($x, $y, new Color($r, $g, $b));

                                // Prevent unbounded dot buffering during long REP fills.
                                if (($offset & 0xFFF) === 0) {
                                    $writer->flushIfNeeded();
                                }
                            }
                        }
                    }
                }

                if ($runtime->logicBoard()->debug()->memoryAccess()->stopOnLfbWrite) {
                    $runtime->option()->logger()->warning(sprintf('LFB: write%d addr=0x%08X value=0x%X', $width, $address & 0xFFFFFFFF, $value & 0xFFFFFFFF));
                    throw new \PHPMachineEmulator\Exception\HaltException('Stopped by PHPME_STOP_ON_LFB_WRITE');
                }

                return true;
            }
        }

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
