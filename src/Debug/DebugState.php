<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Debug;

/**
 * Minimal shared debug state for cross-component instrumentation.
 *
 * This is intentionally small and only used by optional env-driven debug hooks.
 */
final class DebugState
{
    private static ?int $watchArmAfterInt13Lba = null;
    private static bool $watchArmed = true;

    public static function setWatchArmAfterInt13Lba(?int $lba): void
    {
        self::$watchArmAfterInt13Lba = $lba;
        self::$watchArmed = ($lba === null);
    }

    public static function notifyInt13Read(int $startLba, int $sectorCount): void
    {
        if (self::$watchArmed || self::$watchArmAfterInt13Lba === null || $sectorCount <= 0) {
            return;
        }

        $target = self::$watchArmAfterInt13Lba;
        if ($target >= $startLba && $target < ($startLba + $sectorCount)) {
            self::$watchArmed = true;
        }
    }

    public static function isWatchArmed(): bool
    {
        return self::$watchArmed;
    }
}

