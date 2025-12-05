<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * Serial port (UART 8250/16550) I/O ports.
 *
 * Standard PC serial port addresses:
 * - COM1: 0x3F8-0x3FF (IRQ4)
 * - COM2: 0x2F8-0x2FF (IRQ3)
 * - COM3: 0x3E8-0x3EF (IRQ4)
 * - COM4: 0x2E8-0x2EF (IRQ3)
 */
enum Serial: int
{
    // COM1 ports
    case COM1_DATA = 0x3F8;          // Data register (DLAB=0) / Divisor latch low (DLAB=1)
    case COM1_IER = 0x3F9;           // Interrupt Enable Register (DLAB=0) / Divisor latch high (DLAB=1)
    case COM1_IIR_FCR = 0x3FA;       // Interrupt Identification (read) / FIFO Control (write)
    case COM1_LCR = 0x3FB;           // Line Control Register
    case COM1_MCR = 0x3FC;           // Modem Control Register
    case COM1_LSR = 0x3FD;           // Line Status Register
    case COM1_MSR = 0x3FE;           // Modem Status Register
    case COM1_SCRATCH = 0x3FF;       // Scratch Register

    // COM2 ports
    case COM2_DATA = 0x2F8;
    case COM2_IER = 0x2F9;
    case COM2_IIR_FCR = 0x2FA;
    case COM2_LCR = 0x2FB;
    case COM2_MCR = 0x2FC;
    case COM2_LSR = 0x2FD;
    case COM2_MSR = 0x2FE;
    case COM2_SCRATCH = 0x2FF;

    public static function isCom1Port(int $port): bool
    {
        return $port >= self::COM1_DATA->value && $port <= self::COM1_SCRATCH->value;
    }

    public static function isCom2Port(int $port): bool
    {
        return $port >= self::COM2_DATA->value && $port <= self::COM2_SCRATCH->value;
    }
}
