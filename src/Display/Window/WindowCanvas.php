<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

use FFI;
use PHPMachineEmulator\Display\Pixel\Color;

class WindowCanvas
{
    /** @var \FFI\CData Reusable SDL_Rect for single rect operations */
    protected \FFI\CData $reusableRect;

    /** @var int Last set color as packed RGB (for batching) */
    protected int $lastColorPacked = -1;

    /** @var \FFI\CData|null Reusable batch buffer for rectangles */
    protected ?\FFI\CData $batchBuffer = null;

    /** @var int Current batch buffer size */
    protected int $batchBufferSize = 0;

    /** @var int Maximum expected batch size (80*25*~20 pixels per char) */
    protected const MAX_BATCH_SIZE = 50000;

    public function __construct(
        protected Window $window,
        protected FFI $ffi,
        protected mixed $renderer,
    ) {
        $this->reusableRect = $this->ffi->new('SDL_Rect');
        // Pre-allocate batch buffer
        $this->batchBuffer = $this->ffi->new('SDL_Rect[' . self::MAX_BATCH_SIZE . ']');
        $this->batchBufferSize = self::MAX_BATCH_SIZE;
    }

    public function __debugInfo(): array
    {
        return [];
    }

    /**
     * Get batch buffer, expanding if needed
     */
    protected function getBatchBuffer(int $size): \FFI\CData
    {
        if ($size <= $this->batchBufferSize) {
            return $this->batchBuffer;
        }
        // Expand buffer
        $this->batchBuffer = $this->ffi->new("SDL_Rect[{$size}]");
        $this->batchBufferSize = $size;
        return $this->batchBuffer;
    }

    public function pixel(int $x, int $y, Color $color): self
    {
        $this->ffi->SDL_SetRenderDrawColor($this->renderer, $color->red(), $color->green(), $color->blue(), 255);
        $this->ffi->SDL_RenderDrawPoint($this->renderer, $x, $y);

        return $this;
    }

    public function rect(int $x, int $y, int $width, int $height, Color $color): self
    {
        $this->setColor($color);
        $this->reusableRect->x = $x;
        $this->reusableRect->y = $y;
        $this->reusableRect->w = $width;
        $this->reusableRect->h = $height;
        $this->ffi->SDL_RenderFillRect($this->renderer, FFI::addr($this->reusableRect));

        return $this;
    }

    /**
     * Draw multiple rectangles with the same color in a single batch.
     * @param array<array{x: int, y: int, w: int, h: int}> $rects
     */
    public function rectBatch(array $rects, Color $color): self
    {
        if (empty($rects)) {
            return $this;
        }

        $this->setColor($color);
        $count = count($rects);
        $sdlRects = $this->getBatchBuffer($count);

        $i = 0;
        foreach ($rects as $rect) {
            $sdlRects[$i]->x = $rect['x'];
            $sdlRects[$i]->y = $rect['y'];
            $sdlRects[$i]->w = $rect['w'];
            $sdlRects[$i]->h = $rect['h'];
            $i++;
        }

        $this->ffi->SDL_RenderFillRects($this->renderer, $sdlRects, $count);

        return $this;
    }

    /**
     * Set render color only if different from last color (reduces SDL calls).
     */
    protected function setColor(Color $color): void
    {
        $packed = ($color->red() << 16) | ($color->green() << 8) | $color->blue();
        if ($packed !== $this->lastColorPacked) {
            $this->ffi->SDL_SetRenderDrawColor($this->renderer, $color->red(), $color->green(), $color->blue(), 255);
            $this->lastColorPacked = $packed;
        }
    }

    public function text(int $x, int $y, string $text, Color $color, int $scale = 1): self
    {
        $this->setColor($color);
        $charWidth = BitmapFont::charWidth() * $scale;

        // First pass: count total pixels using cached counts
        $totalPixels = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $totalPixels += BitmapFont::getGlyphPixelCount($text[$i]);
        }

        if ($totalPixels === 0) {
            return $this;
        }

        $sdlRects = $this->getBatchBuffer($totalPixels);
        $idx = 0;

        // Second pass: fill buffer directly
        for ($i = 0; $i < $len; $i++) {
            $pixels = BitmapFont::getGlyphPixels($text[$i]);
            if ($pixels === null) {
                continue;
            }
            $baseX = $x + ($i * $charWidth);
            foreach ($pixels as [$px, $py]) {
                $sdlRects[$idx]->x = $baseX + ($px * $scale);
                $sdlRects[$idx]->y = $y + ($py * $scale);
                $sdlRects[$idx]->w = $scale;
                $sdlRects[$idx]->h = $scale;
                $idx++;
            }
        }

        $this->ffi->SDL_RenderFillRects($this->renderer, $sdlRects, $totalPixels);

        return $this;
    }

    /**
     * Draw multiple characters at different positions with the same color in a single batch.
     * @param array<array{x: int, y: int, char: string}> $chars
     */
    public function textBatch(array $chars, Color $color, int $scale = 1): self
    {
        if (empty($chars)) {
            return $this;
        }

        $this->setColor($color);

        // First pass: count total pixels using cached counts
        $totalPixels = 0;
        $count = count($chars);
        for ($c = 0; $c < $count; $c++) {
            $totalPixels += BitmapFont::getGlyphPixelCount($chars[$c]['char']);
        }

        if ($totalPixels === 0) {
            return $this;
        }

        $sdlRects = $this->getBatchBuffer($totalPixels);
        $i = 0;

        // Second pass: fill buffer directly
        for ($c = 0; $c < $count; $c++) {
            $pixels = BitmapFont::getGlyphPixels($chars[$c]['char']);
            if ($pixels === null) {
                continue;
            }
            $baseX = $chars[$c]['x'];
            $baseY = $chars[$c]['y'];
            foreach ($pixels as [$px, $py]) {
                $sdlRects[$i]->x = $baseX + ($px * $scale);
                $sdlRects[$i]->y = $baseY + ($py * $scale);
                $sdlRects[$i]->w = $scale;
                $sdlRects[$i]->h = $scale;
                $i++;
            }
        }

        $this->ffi->SDL_RenderFillRects($this->renderer, $sdlRects, $totalPixels);

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

    public function image(string $path, int $x = 0, int $y = 0, ?int $width = null, ?int $height = null): self
    {
        if (!file_exists($path)) {
            return $this;
        }

        $imageInfo = getimagesize($path);
        if ($imageInfo === false) {
            return $this;
        }

        $gdImage = match ($imageInfo[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            default => false,
        };

        if ($gdImage === false) {
            return $this;
        }

        $srcWidth = imagesx($gdImage);
        $srcHeight = imagesy($gdImage);
        $dstWidth = $width ?? $srcWidth;
        $dstHeight = $height ?? $srcHeight;

        for ($dy = 0; $dy < $dstHeight; $dy++) {
            for ($dx = 0; $dx < $dstWidth; $dx++) {
                $sx = (int) ($dx * $srcWidth / $dstWidth);
                $sy = (int) ($dy * $srcHeight / $dstHeight);

                $rgb = imagecolorat($gdImage, $sx, $sy);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $this->ffi->SDL_SetRenderDrawColor($this->renderer, $r, $g, $b, 255);
                $this->ffi->SDL_RenderDrawPoint($this->renderer, $x + $dx, $y + $dy);
            }
        }

        imagedestroy($gdImage);

        return $this;
    }
}
