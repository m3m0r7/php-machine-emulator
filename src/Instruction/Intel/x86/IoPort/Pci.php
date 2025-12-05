<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * PCI Configuration Space I/O ports.
 *
 * PCI configuration uses mechanism #1:
 * - CONFIG_ADDRESS (0xCF8): 32-bit register to select device/function/register
 * - CONFIG_DATA (0xCFC): 32-bit register to read/write configuration data
 *
 * CONFIG_ADDRESS format:
 * - Bit 31: Enable bit
 * - Bits 23-16: Bus number
 * - Bits 15-11: Device number
 * - Bits 10-8: Function number
 * - Bits 7-0: Register offset (must be aligned to 4 bytes)
 */
enum Pci: int
{
    case CONFIG_ADDRESS = 0xCF8;
    case CONFIG_DATA = 0xCFC;
}
