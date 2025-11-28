<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Display\Window\Window;
use PHPMachineEmulator\Display\Window\WindowCanvas;
use PHPMachineEmulator\Display\Window\WindowOption;
use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Video\VideoTypeInfo;

class WindowScreenWriter implements ScreenWriterInterface
{
    protected Window $window;
    protected WindowCanvas $canvas;
    protected int $cursorX = 0;
    protected int $cursorY = 0;
    protected int $pixelSize;

    /** @var array<int, array<int, string>> Text buffer [row][col] => char */
    protected array $textBuffer = [];

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

        // Store character in text buffer
        $row = $this->cursorY;
        $col = $this->cursorX;

        if (!isset($this->textBuffer[$row])) {
            $this->textBuffer[$row] = [];
        }
        $this->textBuffer[$row][$col] = $value;

        $this->cursorX++;

        // Redraw entire screen
        $this->redrawScreen();
    }

    protected function redrawScreen(): void
    {
        // Clear screen
        $this->canvas->clear(Color::asBlack());

        // Draw all characters from buffer
        $charWidth = 8;
        $charHeight = 16;

        foreach ($this->textBuffer as $row => $cols) {
            foreach ($cols as $col => $char) {
                $x = $col * $charWidth;
                $y = $row * $charHeight;
                $this->canvas->text($x, $y, $char, Color::asWhite(), 1);
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

    public function clear(): void
    {
        $this->canvas->clearChunks();
        $this->textBuffer = [];
        $this->resetCursor();
        $this->canvas->clear(Color::asBlack());
        $this->canvas->present();
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
