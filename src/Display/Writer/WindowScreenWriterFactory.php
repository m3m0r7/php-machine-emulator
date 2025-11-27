<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Window\WindowOption;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class WindowScreenWriterFactory implements ScreenWriterFactoryInterface
{
    public function __construct(
        protected ?WindowOption $windowOption = null,
        protected int $pixelSize = 2,
    ) {
    }

    public function create(RuntimeInterface $runtime, VideoTypeInfo $videoTypeInfo): ScreenWriterInterface
    {
        $writer = new WindowScreenWriter($videoTypeInfo, $this->windowOption, $this->pixelSize);
        $writer->showSplash(__DIR__ . '/../../../src/Asset/splash.png', 1000);
        return $writer;
    }
}
