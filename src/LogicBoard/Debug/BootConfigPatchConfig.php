<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class BootConfigPatchConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $patchGrubPlatform = true,
        public readonly bool $disableLoadfontUnicode = true,
        public readonly ?int $timeoutOverride = 1,
    ) {
    }
}
