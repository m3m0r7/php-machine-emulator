<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Display\Pixel\VgaPaletteColor;
use PHPMachineEmulator\Display\Window\Window;
use PHPMachineEmulator\Display\Window\WindowCanvas;
use PHPMachineEmulator\Display\Window\WindowOption;
use PHPMachineEmulator\Video\VideoTypeInfo;

class WindowScreenWriter implements ScreenWriterInterface
{
    protected Window $window;
    protected WindowCanvas $canvas;
    protected int $cursorX = 0;
    protected int $cursorY = 0;
    protected int $pixelSize;

    /** @var array<int, array<int, array{char: string, attr: int}>> Text buffer [row][col] => {char, attr} */
    protected array $textBuffer = [];

    /** @var int Current text attribute (default: white on black = 0x07) */
    protected int $currentAttribute = 0x07;

    public function __construct(
        protected VideoTypeInfo $videoTypeInfo,
        ?WindowOption $windowOption = null,
        int $pixelSize = 2,
    ) {
        $this->pixelSize = $pixelSize;

        $effectivePixelSize = $videoTypeInfo->isTextMode ? 1 : $pixelSize;
        $windowOption ??= new WindowOption(
            width: $videoTypeInfo->pixelWidth() * $effectivePixelSize,
            height: $videoTypeInfo->pixelHeight() * $effectivePixelSize,
        );

        $this->window = new Window('PHP Machine Emulator', $windowOption);
        $this->window->initialize();
        $this->canvas = $this->window->canvas();

        // Clear the screen to black on initialization
        $this->canvas->clear(Color::asBlack());
        $this->canvas->present();
    }

    public function write(string $value): void
    {
        // Handle control characters
        if ($value === "\r") {
            $this->cursorX = 0;
            return;
        }
        if ($value === "\n") {
            $this->cursorX = 0;
            $this->cursorY++;
            return;
        }

        // Store character and attribute in text buffer
        $row = $this->cursorY;
        $col = $this->cursorX;

        if (!isset($this->textBuffer[$row])) {
            $this->textBuffer[$row] = [];
        }

        // For teletype output (write), preserve existing cell's attribute if available
        // This allows background colors set by AH=09h to be preserved
        $existingAttr = $this->currentAttribute;
        if (isset($this->textBuffer[$row][$col]) && is_array($this->textBuffer[$row][$col])) {
            $existingAttr = $this->textBuffer[$row][$col]['attr'];
        }
        $this->textBuffer[$row][$col] = ['char' => $value, 'attr' => $existingAttr];

        $this->cursorX++;

        // Redraw entire screen
        $this->redrawScreen();
    }

    protected function getColorFromAttribute(int $colorIndex): Color
    {
        return VgaPaletteColor::fromIndex($colorIndex)->toColor();
    }

    protected function redrawScreen(): void
    {
        // Clear screen with default background
        $this->canvas->clear(Color::asBlack());

        $charWidth = 8;
        $charHeight = 16;

        foreach ($this->textBuffer as $row => $cols) {
            foreach ($cols as $col => $cell) {
                $x = $col * $charWidth;
                $y = $row * $charHeight;

                // Handle both old format (string) and new format (array with char/attr)
                if (is_array($cell)) {
                    $char = $cell['char'];
                    $attr = $cell['attr'];
                } else {
                    $char = $cell;
                    $attr = $this->currentAttribute;
                }

                // Extract foreground and background colors from attribute
                $fgColor = $attr & 0x0F;
                $bgColor = ($attr >> 4) & 0x0F;

                // Draw background rectangle
                $bgColorObj = $this->getColorFromAttribute($bgColor);
                $this->canvas->rect($x, $y, $charWidth, $charHeight, $bgColorObj);

                // Draw character with foreground color
                $fgColorObj = $this->getColorFromAttribute($fgColor);
                $this->canvas->text($x, $y, $char, $fgColorObj, 1);
            }
        }

        // Present to screen
        $this->canvas->present();
    }

    public function dot(ColorInterface $color): void
    {
        $x = $this->cursorX * $this->pixelSize;
        $y = $this->cursorY * $this->pixelSize;
        $size = $this->pixelSize;
        $windowColor = new Color($color->red(), $color->green(), $color->blue());

        $this->canvas->add(function (WindowCanvas $canvas) use ($x, $y, $size, $windowColor) {
            $canvas->rect($x, $y, $size, $size, $windowColor);
        });

        $this->cursorX++;

        if ($this->cursorX >= $this->videoTypeInfo->width) {
            $this->cursorX = 0;
            $this->cursorY++;
        }
    }

    public function newline(): void
    {
        $this->cursorX = 0;
        $this->cursorY++;
    }

    public function window(): Window
    {
        return $this->window;
    }

    public function canvas(): WindowCanvas
    {
        return $this->canvas;
    }

    public function resetCursor(): void
    {
        $this->cursorX = 0;
        $this->cursorY = 0;
    }

    public function setCursorPosition(int $row, int $col): void
    {
        $this->cursorY = $row;
        $this->cursorX = $col;
    }

    public function getCursorPosition(): array
    {
        return ['row' => $this->cursorY, 'col' => $this->cursorX];
    }

    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void
    {
        // Write character at current cursor position without advancing cursor
        // This is used by INT 10h AH=09h/0Ah
        // Note: Characters wrap to next line when reaching end of screen width
        $row = $this->cursorY;
        $col = $this->cursorX;
        $attr = $attribute ?? $this->currentAttribute;
        $screenWidth = $this->videoTypeInfo->width; // 80 for text mode

        for ($i = 0; $i < $count; $i++) {
            $currentRow = $row + (int)(($col + $i) / $screenWidth);
            $currentCol = ($col + $i) % $screenWidth;

            if (!isset($this->textBuffer[$currentRow])) {
                $this->textBuffer[$currentRow] = [];
            }
            $this->textBuffer[$currentRow][$currentCol] = ['char' => $char, 'attr' => $attr];
        }

        $this->redrawScreen();
    }

    public function clear(): void
    {
        $this->canvas->clearChunks();
        $this->textBuffer = [];
        $this->resetCursor();
        $this->canvas->clear(Color::asBlack());
        $this->canvas->present();
    }

    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void
    {
        // Fill an area with spaces using the given attribute (for scroll/clear operations)
        for ($r = $row; $r < $row + $height; $r++) {
            if (!isset($this->textBuffer[$r])) {
                $this->textBuffer[$r] = [];
            }
            for ($c = $col; $c < $col + $width; $c++) {
                $this->textBuffer[$r][$c] = ['char' => ' ', 'attr' => $attribute];
            }
        }
        $this->redrawScreen();
    }

    public function setCurrentAttribute(int $attribute): void
    {
        $this->currentAttribute = $attribute;
    }

    public function getCurrentAttribute(): int
    {
        return $this->currentAttribute;
    }

    public function updateVideoMode(VideoTypeInfo $videoTypeInfo): void
    {
        $this->videoTypeInfo = $videoTypeInfo;
        $effectivePixelSize = $videoTypeInfo->isTextMode ? 1 : $this->pixelSize;
        $width = $videoTypeInfo->pixelWidth() * $effectivePixelSize;
        $height = $videoTypeInfo->pixelHeight() * $effectivePixelSize;

        $this->window->resize($width, $height);
        $this->clear();
    }

    public function present(): void
    {
        $this->canvas->present();
    }

    public function start(): void
    {
        $this->window->start();
    }

    public function stop(): void
    {
        $this->window->stop();
    }

    public function showSplash(string $imagePath, int $durationMs = 5000): void
    {
        if (!file_exists($imagePath)) {
            return;
        }

        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            return;
        }

        $imgWidth = $imageInfo[0];
        $imgHeight = $imageInfo[1];

        // Resize window to match image
        $this->window->resize($imgWidth, $imgHeight);

        // Draw splash image
        $this->canvas->clear(Color::asBlack());
        $this->canvas->image($imagePath, 0, 0);
        $this->canvas->present();

        // Wait for duration while handling events
        $startTime = microtime(true) * 1000;
        $frameDelay = 16; // ~60fps

        while ((microtime(true) * 1000 - $startTime) < $durationMs) {
            // Process SDL events to keep window responsive
            $this->window->processEvents();
            usleep($frameDelay * 1000);
        }

        // Clear screen after splash
        $this->canvas->clear(Color::asBlack());
        $this->canvas->present();
    }
}
