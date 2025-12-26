<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class WatchState
{
    private ?int $watchArmAfterInt13Lba = null;
    private bool $watchArmed = true;

    public function setWatchArmAfterInt13Lba(?int $lba): void
    {
        $this->watchArmAfterInt13Lba = $lba;
        $this->watchArmed = ($lba === null);
    }

    public function notifyInt13Read(int $startLba, int $sectorCount): void
    {
        if ($this->watchArmed || $this->watchArmAfterInt13Lba === null || $sectorCount <= 0) {
            return;
        }

        $target = $this->watchArmAfterInt13Lba;
        if ($target >= $startLba && $target < ($startLba + $sectorCount)) {
            $this->watchArmed = true;
        }
    }

    public function isWatchArmed(): bool
    {
        return $this->watchArmed;
    }
}
