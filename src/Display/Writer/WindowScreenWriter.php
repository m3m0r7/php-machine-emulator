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
            $this->cursorY += 16; // Line height
            return;
        }

        // Log printable characters
        $byte = ord($value);
        if ($byte >= 0x20 && $byte < 0x7F) {
            static $screenText = '';
            static $totalChars = 0;
            $screenText .= $value;
            $totalChars++;
            if ($totalChars <= 200) {
                fwrite(STDERR, $value);
                if ($totalChars % 40 === 0) {
                    fwrite(STDERR, "\n");
                }
            }
        }

        $x = $this->cursorX;
        $y = $this->cursorY;
        $this->canvas->add(function (WindowCanvas $canvas) use ($x, $y, $value) {
            $canvas->text($x, $y, $value, Color::asWhite(), 1);
        });
        $this->cursorX += strlen($value) * 8;
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
        $this->resetCursor();
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
