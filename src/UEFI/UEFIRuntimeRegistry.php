<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Runtime\RuntimeInterface;

final class UEFIRuntimeRegistry
{
    /** @var array<int,UEFIDispatcher> */
    private static array $dispatchers = [];

    public static function register(RuntimeInterface $runtime, UEFIDispatcher $dispatcher): void
    {
        self::$dispatchers[spl_object_id($runtime)] = $dispatcher;
    }

    public static function unregister(RuntimeInterface $runtime): void
    {
        unset(self::$dispatchers[spl_object_id($runtime)]);
    }

    public static function dispatcher(RuntimeInterface $runtime): ?UEFIDispatcher
    {
        return self::$dispatchers[spl_object_id($runtime)] ?? null;
    }
}
