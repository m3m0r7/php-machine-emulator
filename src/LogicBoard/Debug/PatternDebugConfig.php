<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class PatternDebugConfig
{
    public function __construct(
        public readonly bool $traceHotPatterns = false,
        public readonly bool $enableLzmaPattern = true,
        public readonly bool $enableLzmaLoopOptimization = false,
    ) {
    }
}
