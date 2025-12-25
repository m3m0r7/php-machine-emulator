<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class TraceDebugConfig
{
    /**
     * @param array<int,true> $stopOnInt13ReadLbaSet
     */
    public function __construct(
        public readonly bool $traceGrubCfgCopy = false,
        public readonly int $traceInt10CallsLimit = 0,
        public readonly int $traceInt13ReadsLimit = 0,
        public readonly bool $traceInt13Caller = false,
        public readonly bool $traceInt15_87 = false,
        public readonly array $stopOnInt13ReadLbaSet = [],
        public readonly bool $stopOnInt10WriteString = false,
        public readonly bool $stopOnSetVideoMode = false,
        public readonly bool $stopOnVbeSetMode = false,
        public readonly bool $stopOnInt16Wait = false,
    ) {
    }
}
