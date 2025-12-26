<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class ScreenDebugConfig
{
    public function __construct(
        public readonly ?string $stopOnScreenSubstr = null,
        public readonly int $stopOnScreenTail = 0,
        public readonly ?ScreenDumpMemoryConfig $dumpMemory = null,
        public readonly bool $dumpScreenAll = false,
        public readonly ?ScreenDumpCodeConfig $dumpCode = null,
        public readonly ?int $dumpStackLength = null,
        public readonly bool $dumpPointerStrings = false,
    ) {
    }
}
