<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

use Closure;
use FFI;
use PHPMachineEmulator\Display\Pixel\Color;

class WindowCanvas
{
    /** @var array<string, Closure(WindowCanvas): void> */
    protected array $chunks = [];

    protected int $chunkId = 0;

    public function __construct(
        protected Window $window,
        protected FFI $ffi,
        protected mixed $renderer,
    ) {
    }

    /**
     * @param Closure(WindowCanvas): void $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->chunks[$name] = $callback;

        return $this;
    }

    /**
     * Register a callback with auto-generated key
     * @param Closure(WindowCanvas): void $callback
     */
    public function add(Closure $callback): self
    {
        $this->chunks['chunk_' . $this->chunkId++] = $callback;

        return $this;
    }

    public function unregister(string $name): self
    {
        unset($this->chunks[$name]);

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->chunks[$name]);
    }

    public function render(): void
    {
        foreach ($this->chunks as $callback) {
            $callback($this);
        }
    }

    public function clearChunks(): self
    {
        $this->chunks = [];

        return $this;
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function pixel(int $x, int $y, Color $color): self
    {
        $this->ffi->SDL_SetRenderDrawColor($this->renderer, $color->red(), $color->green(), $color->blue(), 255);
        $this->ffi->SDL_RenderDrawPoint($this->renderer, $x, $y);

        return $this;
    }

    public function rect(int $x, int $y, int $width, int $height, Color $color): self
    {
        $this->ffi->SDL_SetRenderDrawColor($this->renderer, $color->red(), $color->green(), $color->blue(), 255);
        $rect = $this->ffi->new('SDL_Rect');
        $rect->x = $x;
        $rect->y = $y;
        $rect->w = $width;
        $rect->h = $height;
        $this->ffi->SDL_RenderFillRect($this->renderer, FFI::addr($rect));

        return $this;
    }

    public function text(int $x, int $y, string $text, Color $color, int $scale = 1): self
    {
        $this->ffi->SDL_SetRenderDrawColor($this->renderer, $color->red(), $color->green(), $color->blue(), 255);
        $charWidth = BitmapFont::charWidth() * $scale;

        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            $glyph = BitmapFont::getGlyph($char);

            if ($glyph === null) {
                continue;
            }

            $charX = $x + ($i * $charWidth);

            for ($row = 0; $row < 8; $row++) {
                $rowData = $glyph[$row];
                for ($col = 0; $col < 8; $col++) {
                    if ($rowData & (0x80 >> $col)) {
                        if ($scale === 1) {
                            $this->ffi->SDL_RenderDrawPoint($this->renderer, $charX + $col, $y + $row);
                        } else {
                            $rect = $this->ffi->new('SDL_Rect');
                            $rect->x = $charX + ($col * $scale);
                            $rect->y = $y + ($row * $scale);
                            $rect->w = $scale;
                            $rect->h = $scale;
                            $this->ffi->SDL_RenderFillRect($this->renderer, FFI::addr($rect));
                        }
                    }
                }
            }
        }

        return $this;
    }

    public function textWidth(string $text, int $scale = 1): int
    {
        return strlen($text) * BitmapFont::charWidth() * $scale;
    }

    public function textHeight(int $scale = 1): int
    {
        return BitmapFont::charHeight() * $scale;
    }

    public function clear(Color $color): self
    {
        $this->ffi->SDL_SetRenderDrawColor($this->renderer, $color->red(), $color->green(), $color->blue(), 255);
        $this->ffi->SDL_RenderClear($this->renderer);

        return $this;
    }

    public function present(): self
    {
        $this->ffi->SDL_RenderPresent($this->renderer);

        return $this;
    }

    public function width(): int
    {
        return $this->window->width();
    }

    public function height(): int
    {
        return $this->window->height();
    }
}
