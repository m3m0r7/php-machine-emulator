<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

class BinaryInteger
{
    public static function asLittleEndian(int $value, int $size = 64): int
    {
        // Optimized byte swap without loops for common sizes
        return match ($size) {
            8 => $value & 0xFF,
            16 => (($value & 0xFF) << 8) | (($value >> 8) & 0xFF),
            32 => (($value & 0xFF) << 24) |
                  ((($value >> 8) & 0xFF) << 16) |
                  ((($value >> 16) & 0xFF) << 8) |
                  (($value >> 24) & 0xFF),
            default => self::asLittleEndianLoop($value, $size),
        };
    }

    private static function asLittleEndianLoop(int $value, int $size): int
    {
        $remains = $value;
        $result = 0;
        for ($i = 0; $i < intdiv($size, 8); $i++) {
            $result <<= 8;
            $result += $remains & 0xFF;
            $remains >>= 8;
        }
        return $result;
    }
}
