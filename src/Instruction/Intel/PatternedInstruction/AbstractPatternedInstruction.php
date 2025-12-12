<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;

abstract class AbstractPatternedInstruction implements PatternedInstructionInterface
{
    /**
     * Map register numbers to RegisterType (32-bit mode).
     *
     * @return array<int, RegisterType>
     */
    protected function getRegisterMap(): array
    {
        return [
            0 => RegisterType::EAX,
            1 => RegisterType::ECX,
            2 => RegisterType::EDX,
            3 => RegisterType::EBX,
            4 => RegisterType::ESP,
            5 => RegisterType::EBP,
            6 => RegisterType::ESI,
            7 => RegisterType::EDI,
        ];
    }

    /**
     * Calculate parity flag (true if even number of 1 bits in low byte).
     */
    protected function calculateParity(int $value): bool
    {
        $bits = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($value & (1 << $i)) {
                $bits++;
            }
        }
        return ($bits % 2) === 0;
    }
}
