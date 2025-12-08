<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TerminalScreenWriter implements ScreenWriterInterface
{
    protected int $cursorRow = 0;
    protected int $cursorCol = 0;
    protected int $currentAttribute = 0x07; // Default: white on black

    /** @var string Buffered output for batch writing */
    protected string $outputBuffer = '';

    public function __construct(protected RuntimeInterface $runtime, protected VideoTypeInfo $videoTypeInfo)
    {
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
        // Buffer the cursor move and dot sequence instead of immediate write
        // ANSI escape sequence: move cursor then draw colored space
        $this->outputBuffer .= sprintf(
            "\033[%d;%dH\033[38;2;%d;%d;%d;48;2;%d;%d;%d;1m \033[0m",
            $y + 1,
            $x + 1,
            $color->red(),
            $color->green(),
            $color->blue(),
            $color->red(),
            $color->green(),
            $color->blue(),
        );
    }

    public function newline(): void
    {
        $this->write("\n");
    }

    public function setCursorPosition(int $row, int $col): void
    {
        $this->cursorRow = $row;
        $this->cursorCol = $col;
        // ANSI escape sequence to move cursor: ESC[row;colH
        $this->write(sprintf("\033[%d;%dH", $row + 1, $col + 1));
    }

    public function getCursorPosition(): array
    {
        return ['row' => $this->cursorRow, 'col' => $this->cursorCol];
    }

    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void
    {
        if ($attribute !== null) {
            // Convert VGA attribute to ANSI colors
            $fg = $attribute & 0x0F;
            $bg = ($attribute >> 4) & 0x0F;
            $this->write($this->vgaToAnsi($fg, $bg));
        }
        $this->write(str_repeat($char, $count));
        if ($attribute !== null) {
            $this->write("\033[0m"); // Reset colors
        }
    }

    public function writeCharAt(int $row, int $col, string $char, ?int $attribute = null): void
    {
        // Move cursor to position and write character
        $this->write(sprintf("\033[%d;%dH", $row + 1, $col + 1));
        if ($attribute !== null) {
            $fg = $attribute & 0x0F;
            $bg = ($attribute >> 4) & 0x0F;
            $this->write($this->vgaToAnsi($fg, $bg));
        }
        $this->write($char);
        if ($attribute !== null) {
            $this->write("\033[0m");
        }
        // Update internal cursor position
        $this->cursorRow = $row;
        $this->cursorCol = $col + 1;
    }

    public function clear(): void
    {
        // ANSI escape sequence to clear screen and move cursor to top-left
        $this->write("\033[2J\033[H");
        $this->cursorRow = 0;
        $this->cursorCol = 0;
    }

    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void
    {
        $fg = $attribute & 0x0F;
        $bg = ($attribute >> 4) & 0x0F;
        $colorCode = $this->vgaToAnsi($fg, $bg);

        for ($r = $row; $r < $row + $height; $r++) {
            $this->setCursorPosition($r, $col);
            $this->write($colorCode . str_repeat(' ', $width) . "\033[0m");
        }
    }

    protected function vgaToAnsi(int $fg, int $bg): string
    {
        // VGA to ANSI 256-color mapping
        static $vgaToAnsi256 = [
            0 => 0,    // Black
            1 => 4,    // Blue
            2 => 2,    // Green
            3 => 6,    // Cyan
            4 => 1,    // Red
            5 => 5,    // Magenta
            6 => 3,    // Brown/Yellow
            7 => 7,    // Light Gray
            8 => 8,    // Dark Gray
            9 => 12,   // Light Blue
            10 => 10,  // Light Green
            11 => 14,  // Light Cyan
            12 => 9,   // Light Red
            13 => 13,  // Light Magenta
            14 => 11,  // Yellow
            15 => 15,  // White
        ];

        $ansiFg = $vgaToAnsi256[$fg] ?? 7;
        $ansiBg = $vgaToAnsi256[$bg] ?? 0;

        return sprintf("\033[38;5;%d;48;5;%dm", $ansiFg, $ansiBg);
    }

    public function flushIfNeeded(): void
    {
        if ($this->outputBuffer === '') {
            return;
        }

        // Write all buffered output at once
        $this->write($this->outputBuffer);
        $this->outputBuffer = '';
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
