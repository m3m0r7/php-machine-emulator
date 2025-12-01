<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Video\VideoInterface;

class TestVideo implements VideoInterface
{
    public function videoTypeFlagAddress(): int
    {
        return 0x449;
    }

    public function supportedVideoModes(): array
    {
        return [];
    }
}
