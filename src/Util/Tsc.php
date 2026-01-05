<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

final class Tsc
{
    private static int $last = 0;

    public static function read(): int
    {
        $tsc = (int) (microtime(true) * 1_000_000);
        if ($tsc <= self::$last) {
            $tsc = self::$last + 1;
        }
        self::$last = $tsc;
        return $tsc;
    }
}
