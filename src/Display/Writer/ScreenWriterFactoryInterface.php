<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

interface ScreenWriterFactoryInterface
{
    public function create(RuntimeInterface $runtime, VideoTypeInfo $videoTypeInfo): ScreenWriterInterface;
}
