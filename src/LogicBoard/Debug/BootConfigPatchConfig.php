<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class BootConfigPatchConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly bool $patchGrubPlatform = false,
        public readonly bool $disableLoadfontUnicode = false,
        public readonly bool $forceGrubTextMode = false,
        public readonly bool $disableDosCdromDrivers = false,
        public readonly ?int $timeoutOverride = null,
        public readonly bool $disableSyslinuxUi = false,
        public readonly ?int $syslinuxTimeoutOverride = null,
    ) {
    }
}
