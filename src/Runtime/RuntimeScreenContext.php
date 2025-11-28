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
        // Initialize with default video mode (0x03 - 80x25 text mode for BIOS compatibility)
        $defaultVideoMode = 0x03;
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

    public function setCursorPosition(int $row, int $col): void
    {
        $this->screenWriter->setCursorPosition($row, $col);
    }

    public function getCursorPosition(): array
    {
        return $this->screenWriter->getCursorPosition();
    }

    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void
    {
        $this->screenWriter->writeCharAtCursor($char, $count, $attribute);
    }

    public function clear(): void
    {
        if (method_exists($this->screenWriter, 'clear')) {
            $this->screenWriter->clear();
        }
    }

    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void
    {
        $this->screenWriter->fillArea($row, $col, $width, $height, $attribute);
    }
}
