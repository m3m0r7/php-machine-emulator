<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * PIC (Programmable Interrupt Controller) 8259A I/O ports.
 *
 * The PC uses two cascaded 8259A PICs:
 * - Master PIC: handles IRQ0-7
 * - Slave PIC: handles IRQ8-15, connected to master's IRQ2
 */
enum Pic: int
{
    case MASTER_COMMAND = 0x20;
    case MASTER_DATA = 0x21;
    case SLAVE_COMMAND = 0xA0;
    case SLAVE_DATA = 0xA1;

    public static function isMasterPort(int $port): bool
    {
        return $port === self::MASTER_COMMAND->value || $port === self::MASTER_DATA->value;
    }

    public static function isSlavePort(int $port): bool
    {
        return $port === self::SLAVE_COMMAND->value || $port === self::SLAVE_DATA->value;
    }

    public static function isCommandPort(int $port): bool
    {
        return $port === self::MASTER_COMMAND->value || $port === self::SLAVE_COMMAND->value;
    }

    public static function isDataPort(int $port): bool
    {
        return $port === self::MASTER_DATA->value || $port === self::SLAVE_DATA->value;
    }
}
