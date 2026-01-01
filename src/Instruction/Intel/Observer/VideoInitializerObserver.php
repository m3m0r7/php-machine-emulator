<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Cursor;
use PHPMachineEmulator\Display\CursorInterface;
use PHPMachineEmulator\Display\Pixel\VgaPaletteColor;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoInitializerObserver implements MemoryAccessorObserverInterface
{
    protected ?CursorInterface $cursor = null;

    public function addressRange(): ?array
    {
        // Video type flag is stored at a fixed address.
        $addr = 0xFF0000;
        return ['min' => $addr, 'max' => $addr];
    }

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

        $videoModes = $runtime->video()->supportedVideoModes();
        $videoTypeInfo = $videoModes[$videoType] ?? $videoModes[0x03] ?? null;
        if ($videoTypeInfo === null) {
            return;
        }

        // NOTE: Fallback to predefined size if header was not set.
        $width = $width === 0 ? $videoTypeInfo->width : $width;
        $height = $height === 0 ? $videoTypeInfo->height : $height;

        // NOTE: Clear the screen with a tiny bootstrap text area (mode 0x00) to avoid rendering an
        // enormous frame when switching video modes during boot.
        $bootstrapVideoType = $videoModes[0x00] ?? $videoTypeInfo;
        $clearWidth = $bootstrapVideoType->width;
        $clearHeight = $bootstrapVideoType->height;

        $screenWriter = $runtime->context()->screen()->screenWriter();
        $this->cursor ??= new Cursor($screenWriter);
        $black = VgaPaletteColor::Black->toColor();

        for ($y = 0; $y < $clearHeight; $y++) {
            for ($x = 0; $x < $clearWidth; $x++) {
                $screenWriter->dot($x, $y, $black);
            }
        }

        // Roll back to cursor.
        $this->cursor->reset();
    }
}
