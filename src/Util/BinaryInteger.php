<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

class BinaryInteger
{
    public static function asLittleEndian(int $value, int $size = 64): int
    {
        $remains = $value;
        $value = 0;
        for ($i = 0; $i < intdiv($size, 8); $i++) {
            $value <<= 8;
            $value += $remains & 0xFF;
            $remains >>= 8;
        }

        return $value;
    }
}
