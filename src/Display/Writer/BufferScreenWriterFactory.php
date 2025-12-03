<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class BufferScreenWriterFactory implements ScreenWriterFactoryInterface
{
    private ?ScreenWriterInterface $created = null;

    public function create(RuntimeInterface $runtime, VideoTypeInfo $videoTypeInfo): ScreenWriterInterface
    {
        return $this->created ??= new BufferScreenWriter($runtime, $videoTypeInfo);
    }
}
