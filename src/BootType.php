<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

enum BootType
{
    case BOOT_SIGNATURE;  // Standard MBR with 0xAA55 signature at offset 510-511
    case EL_TORITO;       // El Torito CD-ROM boot (no signature check)
}
