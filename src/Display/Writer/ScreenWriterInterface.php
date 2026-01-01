<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

interface ScreenWriterInterface
{
    public function start(): void;
    public function stop(): void;
    public function updateVideoMode(VideoTypeInfo $videoTypeInfo): void;
    public function write(string $value): void;
    public function newline(): void;
    public function dot(int $x, int $y, ColorInterface $color): void;
    public function setCursorPosition(int $row, int $col): void;
    public function getCursorPosition(): array;
    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void;
    public function writeCharAt(int $row, int $col, string $char, ?int $attribute = null): void;
    public function clear(): void;
    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void;
    public function scrollUpWindow(
        int $topRow,
        int $leftCol,
        int $bottomRow,
        int $rightCol,
        int $lines,
        int $attribute
    ): void;
    public function flushIfNeeded(): void;
    public function setCurrentAttribute(int $attribute): void;
    public function getCurrentAttribute(): int;
}
