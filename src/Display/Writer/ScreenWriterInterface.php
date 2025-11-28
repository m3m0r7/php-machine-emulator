<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;

interface ScreenWriterInterface
{
    public function write(string $value): void;
    public function newline(): void;
    public function dot(ColorInterface $color): void;
    public function setCursorPosition(int $row, int $col): void;
    public function getCursorPosition(): array;
    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void;
    public function clear(): void;
    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void;
}
