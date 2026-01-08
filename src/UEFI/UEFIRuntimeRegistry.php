<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentInterface;

final class UEFIRuntimeRegistry
{
    /** @var array<int,UEFIDispatcher> */
    private static array $dispatchers = [];

    /** @var array<int,UEFIEnvironmentInterface> */
    private static array $environments = [];

    public static function register(RuntimeInterface $runtime, UEFIDispatcher $dispatcher, ?UEFIEnvironmentInterface $environment = null): void
    {
        $id = spl_object_id($runtime);
        self::$dispatchers[$id] = $dispatcher;
        if ($environment !== null) {
            self::$environments[$id] = $environment;
        }
    }

    public static function unregister(RuntimeInterface $runtime): void
    {
        $id = spl_object_id($runtime);
        unset(self::$dispatchers[$id], self::$environments[$id]);
    }

    public static function dispatcher(RuntimeInterface $runtime): ?UEFIDispatcher
    {
        return self::$dispatchers[spl_object_id($runtime)] ?? null;
    }

    public static function environment(RuntimeInterface $runtime): ?UEFIEnvironmentInterface
    {
        return self::$environments[spl_object_id($runtime)] ?? null;
    }
}
