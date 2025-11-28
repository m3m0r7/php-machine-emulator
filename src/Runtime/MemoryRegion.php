<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

/**
 * Memory region definitions for MMIO and special address ranges.
 */
enum MemoryRegion: int
{
    // Legacy VGA memory window
    case VgaMemoryStart = 0xA0000;
    case VgaMemoryEnd = 0xC0000;

    // PCI VGA BAR region
    case PciVgaBarStart = 0xE0000000;
    case PciVgaBarEnd = 0xE1000000;

    // LAPIC MMIO page
    case LapicMmioStart = 0xFEE00000;
    case LapicMmioEnd = 0xFEE01000;

    // IOAPIC MMIO page
    case IoapicMmioStart = 0xFEC00000;
    case IoapicMmioEnd = 0xFEC01000;

    /**
     * Check if an address falls within a known MMIO/VRAM region.
     */
    public static function isKnownRegion(int $address): bool
    {
        return self::isVgaMemory($address)
            || self::isPciVgaBar($address)
            || self::isLapicMmio($address)
            || self::isIoapicMmio($address);
    }

    /**
     * Check if address is in legacy VGA memory window.
     */
    public static function isVgaMemory(int $address): bool
    {
        return $address >= self::VgaMemoryStart->value && $address < self::VgaMemoryEnd->value;
    }

    /**
     * Check if address is in PCI VGA BAR region.
     */
    public static function isPciVgaBar(int $address): bool
    {
        return $address >= self::PciVgaBarStart->value && $address < self::PciVgaBarEnd->value;
    }

    /**
     * Check if address is in LAPIC MMIO page.
     */
    public static function isLapicMmio(int $address): bool
    {
        return $address >= self::LapicMmioStart->value && $address < self::LapicMmioEnd->value;
    }

    /**
     * Check if address is in IOAPIC MMIO page.
     */
    public static function isIoapicMmio(int $address): bool
    {
        return $address >= self::IoapicMmioStart->value && $address < self::IoapicMmioEnd->value;
    }

    /**
     * Get the region name for an address.
     */
    public static function regionName(int $address): ?string
    {
        if (self::isVgaMemory($address)) {
            return 'VGA Memory';
        }
        if (self::isPciVgaBar($address)) {
            return 'PCI VGA BAR';
        }
        if (self::isLapicMmio($address)) {
            return 'LAPIC MMIO';
        }
        if (self::isIoapicMmio($address)) {
            return 'IOAPIC MMIO';
        }
        return null;
    }
}
