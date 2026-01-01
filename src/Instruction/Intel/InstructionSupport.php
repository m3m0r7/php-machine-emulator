<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\RegisterNotFoundException;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;

trait InstructionSupport
{
    public function makeKeyByOpCodes(int|array $opcodes): string
    {
        return '0x' . implode(
            array_map(
                fn ($opcode) => sprintf('%02X', $opcode),
                is_array($opcodes) ? $opcodes : [$opcodes]
            )
        );
    }
}
