<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;

abstract class AbstractPatternedInstruction implements PatternedInstructionInterface
{
    protected const VIDEO_MEMORY_MIN = 0xA0000;
    protected const VIDEO_MEMORY_MAX = 0xBFFFF;
    protected const VIDEO_TYPE_FLAG_ADDRESS = 0xFF0000;

    protected static function rangeOverlapsObserverMemory(int $start, int $length): bool
    {
        if ($length <= 0) {
            return false;
        }

        $start32 = $start & 0xFFFFFFFF;
        $end = $start32 + ($length - 1);
        if ($end > 0xFFFFFFFF) {
            $end = 0xFFFFFFFF;
        }

        if ($start32 <= self::VIDEO_TYPE_FLAG_ADDRESS && $end >= self::VIDEO_TYPE_FLAG_ADDRESS) {
            return true;
        }

        return !($end < self::VIDEO_MEMORY_MIN || $start32 > self::VIDEO_MEMORY_MAX);
    }

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
