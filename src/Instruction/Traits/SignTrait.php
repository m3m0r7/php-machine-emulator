<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

/**
 * Trait for sign extension operations.
 */
trait SignTrait
{
    /**
     * Sign-extend a value from the given bit size to PHP int (64-bit).
     */
    protected function signExtend(int $value, int $bits): int
    {
        if ($bits >= 64) {
            return $value;
        }

        if ($bits >= 32) {
            $value &= 0xFFFFFFFF;
            return ($value & 0x80000000) ? $value - 0x100000000 : $value;
        }

        $mask = 1 << ($bits - 1);
        $fullMask = (1 << $bits) - 1;
        $value &= $fullMask;

        return ($value & $mask) ? $value - (1 << $bits) : $value;
    }
}
