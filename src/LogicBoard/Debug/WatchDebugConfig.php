<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class WatchDebugConfig
{
    public function __construct(
        public readonly ?WatchAccessConfig $access = null,
        public readonly bool $stopOnWatchHit = false,
        public readonly bool $dumpCallsiteOnWatchHit = false,
        public readonly bool $dumpIpOnWatchHit = false,
        public readonly int $dumpCallsiteBytes = 512,
        public readonly bool $watchMsDosBoot = false,
        public readonly bool $stopOnRspZero = false,
        public readonly int $stopOnRspBelowThreshold = 0,
        public readonly bool $stopOnStackUnderflow = false,
    ) {
    }
}
