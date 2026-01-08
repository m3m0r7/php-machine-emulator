<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class BufferScreenWriter implements ScreenWriterInterface
{
    protected int $cursorRow = 0;
    protected int $cursorCol = 0;
    protected int $currentAttribute = 0x07; // Default: white on black

    public function __construct(protected RuntimeInterface $runtime, protected VideoTypeInfo $videoTypeInfo)
    {
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function updateVideoMode(VideoTypeInfo $videoTypeInfo): void
    {
        $this->videoTypeInfo = $videoTypeInfo;
    }

    public function write(string $value): void
    {
        $this->runtime
            ->option()
            ->IO()
            ->output()
            ->write($value);
    }

    public function dot(int $x, int $y, ColorInterface $color): void
    {
        // Buffer mode: no visual output for dots
    }

    public function newline(): void
    {
        $this->write("\n");
    }

    public function setCursorPosition(int $row, int $col): void
    {
        $this->cursorRow = $row;
        $this->cursorCol = $col;
    }

    public function getCursorPosition(): array
    {
        return ['row' => $this->cursorRow, 'col' => $this->cursorCol];
    }

    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void
    {
        $this->write(str_repeat($char, $count));
    }

    public function writeCharAt(int $row, int $col, string $char, ?int $attribute = null): void
    {
        // Buffer mode: just write the character (no positioning)
        $this->write($char);
    }

    public function clear(): void
    {
        $this->cursorRow = 0;
        $this->cursorCol = 0;
    }

    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void
    {
        // Buffer mode: no visual output for fill
    }

    public function scrollUpWindow(
        int $topRow,
        int $leftCol,
        int $bottomRow,
        int $rightCol,
        int $lines,
        int $attribute
    ): void {
        // Buffer mode: no visual output for scroll
    }

    public function flushIfNeeded(): void
    {
        // Buffer mode: no batching needed
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
