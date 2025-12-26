<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class ScreenDumpMemoryConfig
{
    public function __construct(
        public readonly int $address,
        public readonly int $length,
        public readonly bool $save = false,
    ) {
    }
}
