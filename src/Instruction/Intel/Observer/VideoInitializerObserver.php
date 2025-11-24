<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Cursor;
use PHPMachineEmulator\Display\CursorInterface;
use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Display\Writer\TerminalScreenWriter;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoInitializerObserver implements MemoryAccessorObserverInterface
{
    protected ?ScreenWriterInterface $writer = null;
    protected ?CursorInterface $cursor = null;

    public function shouldMatch(RuntimeInterface $runtime, int $address, ?int $previousValue, ?int $nextValue): bool
    {
        return $address === $runtime->video()->videoTypeFlagAddress();
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): void
    {
        $videoSettingAddress = $runtime
            ->memoryAccessor()
            ->fetch(
                $runtime->video()->videoTypeFlagAddress(),
            )
            ->asBytesBySize(64);

        $width = ($videoSettingAddress >> 48) & 0xFFFF;
        $height = ($videoSettingAddress >> 32) & 0xFFFF;
        $videoType = $videoSettingAddress & 0xFF;

        $videoTypeInfo = $runtime
            ->video()
            ->supportedVideoModes()[$videoType];

        // NOTE: Fallback to predefined size if header was not set.
        $width = $width === 0 ? $videoTypeInfo->width : $width;
        $height = $height === 0 ? $videoTypeInfo->height : $height;

        // NOTE: Clear the screen with a tiny bootstrap text area (mode 0x00) to avoid rendering an
        // enormous frame when switching video modes during boot.
        $bootstrapVideoType = $runtime->video()->supportedVideoModes()[0x00] ?? $videoTypeInfo;
        $clearWidth = $bootstrapVideoType->width;
        $clearHeight = $bootstrapVideoType->height;

        $this->writer ??= new TerminalScreenWriter(
            $runtime,
            $videoTypeInfo,
        );

        $this->cursor ??= new Cursor($this->writer);

        for ($i = 0; $i < $clearWidth * $clearHeight; $i++) {
            $this->writer->dot(Color::asBlack());
            if (($i % $clearWidth) === 0) {
                $this->writer->newline();
            }
        }

        // Roll back to cursor.
        $this->cursor->reset();
    }
}
