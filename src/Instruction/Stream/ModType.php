<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Stream;

use PHPMachineEmulator\Stream\StreamReaderInterface;

enum ModType: int
{
    case NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT = 0b00;
    case SIGNED_8BITS_DISPLACEMENT = 0b01;
    case SIGNED_16BITS_DISPLACEMENT = 0b10;
    case REGISTER_TO_REGISTER = 0b11;
}
