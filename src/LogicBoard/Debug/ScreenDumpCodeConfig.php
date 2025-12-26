<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class ScreenDumpCodeConfig
{
    public function __construct(
        public readonly int $length,
        public readonly int $before,
    ) {
    }
}
