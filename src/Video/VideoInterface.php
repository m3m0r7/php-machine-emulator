<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Video;

interface VideoInterface
{
    public function videoTypeFlagAddress(): int;
    public function supportedVideoModes(): array;
}
