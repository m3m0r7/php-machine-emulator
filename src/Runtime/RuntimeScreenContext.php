<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface;
use PHPMachineEmulator\Video\VideoInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class RuntimeScreenContext implements RuntimeScreenContextInterface
{
    private ScreenWriterInterface $screenWriter;

    public function __construct(
        ScreenWriterFactoryInterface $screenWriterFactory,
        RuntimeInterface $runtime,
        VideoInterface $video,
    ) {
        // Initialize with default video mode (0x13 - 320x200 graphics mode)
        $defaultVideoMode = 0x13;
        $videoTypeInfo = $video->supportedVideoModes()[$defaultVideoMode];

        $this->screenWriter = $screenWriterFactory->create($runtime, $videoTypeInfo);
    }

    public function screenWriter(): ScreenWriterInterface
    {
        return $this->screenWriter;
    }

    public function write(string $value): void
    {
        $this->screenWriter->write($value);
    }

    public function start(): void
    {
        if (method_exists($this->screenWriter, 'start')) {
            $this->screenWriter->start();
        }
    }

    public function stop(): void
    {
        if (method_exists($this->screenWriter, 'stop')) {
            $this->screenWriter->stop();
        }
    }

    public function updateVideoMode(VideoTypeInfo $videoTypeInfo): void
    {
        if (method_exists($this->screenWriter, 'updateVideoMode')) {
            $this->screenWriter->updateVideoMode($videoTypeInfo);
        }
    }
}
