<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

enum DriveType: string
{
    case FLOPPY = 'floppy';
    case HARD_DISK = 'hard_disk';
    case CD_ROM = 'cd_rom';
    case CD_RAM = 'cd_ram';
    case EXTERNAL = 'external';
}
