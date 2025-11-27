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

        $windowOption ??= new WindowOption(
            width: $videoTypeInfo->width * $pixelSize,
            height: $videoTypeInfo->height * $pixelSize,
        );

        $this->window = new Window('PHP Machine Emulator', $windowOption);
        $this->window->initialize();
        $this->canvas = $this->window->canvas();
    }

    public function write(string $value): void
    {
        $color = Color::asWhite();
        $this->canvas->text($this->cursorX, $this->cursorY, $value, $color, 1);
        $this->canvas->present();
        $this->cursorX += strlen($value) * 8;
    }

    public function dot(ColorInterface $color): void
    {
        $windowColor = new Color($color->red(), $color->green(), $color->blue());
        $this->canvas->rect(
            $this->cursorX * $this->pixelSize,
            $this->cursorY * $this->pixelSize,
            $this->pixelSize,
            $this->pixelSize,
            $windowColor
        );
        $this->canvas->present();
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
        $this->canvas->clear(Color::asBlack());
        $this->resetCursor();
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
}
