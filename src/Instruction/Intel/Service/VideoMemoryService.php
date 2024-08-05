<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Service;

use PHPMachineEmulator\Instruction\ServiceInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryService implements ServiceInterface
{
    public const VIDEO_MEMORY_ADDRESS_STARTED = 0xA000;
    public const VIDEO_MEMORY_ADDRESS_ENDED = 0xAFFF;

    public function initialize(RuntimeInterface $runtime): void
    {
        $runtime->memoryAccessor()
            ->allocate(
                self::VIDEO_MEMORY_ADDRESS_STARTED,
                self::VIDEO_MEMORY_ADDRESS_ENDED - self::VIDEO_MEMORY_ADDRESS_STARTED,
            );
    }
}
