<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Pixel\VgaPaletteColor;
use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryObserver implements MemoryAccessorObserverInterface
{
    protected ?ScreenWriterInterface $writer = null;
    /** @var array<int, Color> */
    private array $colorCache = [];

    public function addressRange(): ?array
    {
        return [
            'min' => VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED,
            'max' => VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED,
        ];
    }

    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool
    {
        // Text mode video memory (0xB8000-0xBFFFF): always match for character output
        if ($address >= 0xB8000 && $address < 0xC0000) {
            return true;
        }

        // Graphics mode (0xA0000-0xB7FFF): any write in range maps to VRAM
        return true;
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): void
    {
        // Text mode video memory starts at 0xB8000
        // Each character is 2 bytes: [char code][attribute]
        // Only process character bytes (even offsets from 0xB8000)
        $textModeBase = 0xB8000;
        if ($address >= $textModeBase && $address < 0xC0000) {
            $offset = $address - $textModeBase;
            // Only process character bytes (even offsets), skip attribute bytes (odd offsets)
            if (($offset % 2) === 0 && $nextValue !== null) {
                // Calculate row/col from offset
                // Each row is 160 bytes (80 chars * 2 bytes per char)
                $charOffset = $offset / 2;
                $cols = 80; // Standard text mode width
                $row = (int) ($charOffset / $cols);
                $col = $charOffset % $cols;

                // Use ANSI parser for VRAM writes - SYSLINUX writes escape sequences to VRAM
                $videoContext = $runtime->context()->devices()->video();
                $ansiParser = $videoContext->ansiParser();

                // Process character through ANSI parser
                if ($ansiParser->processChar($nextValue, $runtime, $videoContext)) {
                    // Character was consumed by ANSI parser (part of escape sequence)
                    return;
                }

                // Write character to screen at calculated position
                if ($nextValue >= 0x20 && $nextValue < 0x7F) {
                    // Printable character - write to screen buffer
                    $char = chr($nextValue);
                    $runtime->context()->screen()->writeCharAt($row, $col, $char);
                }
            }
            // Attribute byte or non-printable - ignore
            return;
        }

        // Graphics mode handling (0xA0000-0xB7FFF)
        $videoSettingAddress = $runtime
            ->memoryAccessor()
            ->fetch(
                $runtime->video()
                    ->videoTypeFlagAddress(),
            )
            ->asBytesBySize(64);

        $width = ($videoSettingAddress >> 48) & 0xFFFF;
        $videoType = $videoSettingAddress & 0xFF;

        $videoTypeInfo = $runtime->video()->supportedVideoModes()[$videoType];

        $width = $width === 0 ? $videoTypeInfo->width : $width;

        $this->writer ??= $runtime
            ->context()
            ->screen()
            ->screenWriter();

        $textColor = $nextValue & 0b00001111;

        // Calculate x, y from video memory offset
        $videoMemoryOffset = $address - VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED;
        $x = $videoMemoryOffset % $width;
        $y = (int) ($videoMemoryOffset / $width);

        $this->writer->dot(
            $x,
            $y,
            $this->colorForAnsi($textColor),
        );
    }

    private function colorForAnsi(int $color): Color
    {
        $index = $color & 0x0F;
        if (!isset($this->colorCache[$index])) {
            $this->colorCache[$index] = VgaPaletteColor::fromIndex($index)->toColor();
        }
        return $this->colorCache[$index];
    }
}
