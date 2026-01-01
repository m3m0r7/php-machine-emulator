<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TestScreenWriter implements ScreenWriterInterface
{
    private string $output = '';
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private int $currentAttribute = 0x07;

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function updateVideoMode(VideoTypeInfo $videoTypeInfo): void
    {
    }

    public function write(string $value): void
    {
        $this->output .= $value;
    }

    public function newline(): void
    {
        $this->output .= "\n";
        $this->cursorRow++;
        $this->cursorCol = 0;
    }

    public function dot(int $x, int $y, ColorInterface $color): void
    {
        // No-op for testing
    }

    public function setCursorPosition(int $row, int $col): void
    {
        $this->cursorRow = $row;
        $this->cursorCol = $col;
    }

    public function getCursorPosition(): array
    {
        return [$this->cursorRow, $this->cursorCol];
    }

    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void
    {
        $this->output .= str_repeat($char, $count);
        $this->cursorCol += $count;
    }

    public function writeCharAt(int $row, int $col, string $char, ?int $attribute = null): void
    {
        $this->setCursorPosition($row, $col);
        if ($attribute !== null) {
            $this->setCurrentAttribute($attribute);
        }
        $this->writeCharAtCursor($char, 1, $attribute);
    }

    public function clear(): void
    {
        $this->output = '';
        $this->cursorRow = 0;
        $this->cursorCol = 0;
    }

    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void
    {
        // No-op for testing
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function flushIfNeeded(): void
    {
        // No-op for testing
    }

    public function setCurrentAttribute(int $attribute): void
    {
        $this->currentAttribute = $attribute;
    }

    public function getCurrentAttribute(): int
    {
        return $this->currentAttribute;
    }
}
