<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TerminalScreenWriterFactory implements ScreenWriterFactoryInterface
{
    public function create(RuntimeInterface $runtime, VideoTypeInfo $videoTypeInfo): ScreenWriterInterface
    {
        return new TerminalScreenWriter($runtime, $videoTypeInfo);
    }
}
