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

    public function observe(RuntimeInterface $runtime, int $address, ?int $value): void
    {
        $videoSettingAddress = $runtime
            ->memoryAccessor()
            ->fetch(
                $runtime->video()->videoTypeFlagAddress(),
            )
            ->asByte();

        $width = $videoSettingAddress >> 24;
        $height = ($videoSettingAddress >> 8) & 0b11111111_11111111;
        $videoType = $videoSettingAddress & 0b11111111;

        $videoTypeInfo = $runtime
            ->video()
            ->supportedVideoModes()[$videoType];

        $this->writer ??= new TerminalScreenWriter(
            $runtime,
            $videoTypeInfo,
        );

        $this->cursor ??= new Cursor($this->writer);

        for ($i = 0; $i < $videoTypeInfo->width * $videoTypeInfo->height; $i++) {
            $this->writer->dot(Color::asBlack());
            if (($i % $videoTypeInfo->width) === 0) {
                $this->writer->newline();
            }
        }

        // Roll back to cursor
        $this->cursor->reset();
    }
}
