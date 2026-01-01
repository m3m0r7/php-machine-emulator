<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * ATA/IDE Controller I/O ports.
 *
 * Standard PC ATA controller addresses:
 * - Primary: 0x1F0-0x1F7, 0x3F6 (IRQ14)
 * - Secondary: 0x170-0x177, 0x376 (IRQ15)
 */
enum Ata: int
{
    // Primary ATA controller
    case PRIMARY_DATA = 0x1F0;
    case PRIMARY_ERROR = 0x1F1;           // Read: Error, Write: Features
    case PRIMARY_SECTOR_COUNT = 0x1F2;
    case PRIMARY_LBA_LOW = 0x1F3;         // Sector number / LBA bits 0-7
    case PRIMARY_LBA_MID = 0x1F4;         // Cylinder low / LBA bits 8-15
    case PRIMARY_LBA_HIGH = 0x1F5;        // Cylinder high / LBA bits 16-23
    case PRIMARY_DRIVE_HEAD = 0x1F6;      // Drive/Head / LBA bits 24-27
    case PRIMARY_STATUS_COMMAND = 0x1F7;  // Read: Status, Write: Command
    case PRIMARY_ALT_STATUS = 0x3F6;      // Alternate Status / Device Control

    // Secondary ATA controller
    case SECONDARY_DATA = 0x170;
    case SECONDARY_ERROR = 0x171;
    case SECONDARY_SECTOR_COUNT = 0x172;
    case SECONDARY_LBA_LOW = 0x173;
    case SECONDARY_LBA_MID = 0x174;
    case SECONDARY_LBA_HIGH = 0x175;
    case SECONDARY_DRIVE_HEAD = 0x176;
    case SECONDARY_STATUS_COMMAND = 0x177;
    case SECONDARY_ALT_STATUS = 0x376;

    // Bus Master IDE (typical base address)
    case BUS_MASTER_BASE = 0xCC00;

    public static function isPrimaryPort(int $port): bool
    {
        return ($port >= self::PRIMARY_DATA->value && $port <= self::PRIMARY_STATUS_COMMAND->value)
            || $port === self::PRIMARY_ALT_STATUS->value;
    }

    public static function isSecondaryPort(int $port): bool
    {
        return ($port >= self::SECONDARY_DATA->value && $port <= self::SECONDARY_STATUS_COMMAND->value)
            || $port === self::SECONDARY_ALT_STATUS->value;
    }

    public static function isBusMasterPort(int $port): bool
    {
        return $port >= self::BUS_MASTER_BASE->value && $port <= self::BUS_MASTER_BASE->value + 7;
    }

    public static function isDataPort(int $port): bool
    {
        return $port === self::PRIMARY_DATA->value || $port === self::SECONDARY_DATA->value;
    }

    public static function isStatusPort(int $port): bool
    {
        return $port === self::PRIMARY_STATUS_COMMAND->value
            || $port === self::SECONDARY_STATUS_COMMAND->value
            || $port === self::PRIMARY_ALT_STATUS->value
            || $port === self::SECONDARY_ALT_STATUS->value;
    }

    public static function isRegisterPort(int $port): bool
    {
        return ($port >= self::PRIMARY_ERROR->value && $port <= self::PRIMARY_DRIVE_HEAD->value)
            || ($port >= self::SECONDARY_ERROR->value && $port <= self::SECONDARY_DRIVE_HEAD->value);
    }

    public static function isWritableRegisterPort(int $port): bool
    {
        return ($port >= self::PRIMARY_ERROR->value && $port <= self::PRIMARY_STATUS_COMMAND->value)
            || ($port >= self::SECONDARY_ERROR->value && $port <= self::SECONDARY_STATUS_COMMAND->value);
    }
}
