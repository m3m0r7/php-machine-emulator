<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class MemoryAccessDebugConfig
{
    public function __construct(
        public readonly bool $renderLfbToTerminal = false,
        public readonly bool $stopOnLfbWrite = false,
    ) {
    }
}
