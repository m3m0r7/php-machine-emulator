<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Display\Pixel\VgaPaletteColor;
use PHPMachineEmulator\Display\Window\Window;
use PHPMachineEmulator\Display\Window\WindowCanvas;
use PHPMachineEmulator\Display\Window\WindowOption;
use PHPMachineEmulator\Exception\HaltException;
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

    /** @var array<int, array<int, array{char: string, attr: int}>> Previous frame buffer for diff rendering */
    protected array $prevBuffer = [];

    /** @var array<int, array{row: int, col: int}> List of dirty cells that need redraw */
    protected array $dirtyCells = [];

    /** @var int Current text attribute (default: white on black = 0x07) */
    protected int $currentAttribute = 0x07;

    /** @var bool Screen needs full redraw */
    protected bool $dirty = false;

    /** @var float Last redraw time */
    protected float $lastRedrawTime = 0;

    /** @var float Minimum interval between redraws (seconds) */
    protected const REDRAW_INTERVAL = 0.008; // ~120 FPS for smoother updates

    /** @var array<int, Color> Cached VGA color objects */
    protected array $colorCache = [];

    /** @var array<int, array{x: int, y: int, color: Color}> Buffered dot writes */
    protected array $dotBuffer = [];

    /** @var array<int, int> Packed RGB framebuffer for graphics mode */
    protected array $graphicsBuffer = [];
    protected int $graphicsWidth = 0;
    protected int $graphicsHeight = 0;
    protected bool $graphicsDirty = false;
    /** @var array<int, Color> */
    protected array $graphicsColorCache = [];
    protected bool $useFramebuffer = false;
    protected bool $graphicsNeedsClear = false;
    protected ?\FFI\CData $graphicsTexture = null;
    protected int $graphicsTextureWidth = 0;
    protected int $graphicsTextureHeight = 0;
    protected ?\FFI\CData $graphicsPixelBuffer = null;
    protected int $graphicsPixelBufferSize = 0;

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
        $this->canvas->clear($this->getColorFromAttribute(0));
        $this->canvas->present();
        $this->resetGraphicsBuffer();
        $this->useFramebuffer = $windowOption->useFramebuffer;
        $this->graphicsNeedsClear = true;
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

        // Mark for redraw (batched)
        $this->markDirty();
    }

    protected function getColorFromAttribute(int $colorIndex): Color
    {
        if (!isset($this->colorCache[$colorIndex])) {
            $this->colorCache[$colorIndex] = VgaPaletteColor::fromIndex($colorIndex)->toColor();
        }
        return $this->colorCache[$colorIndex];
    }

    protected function redrawScreen(): void
    {
        // Clear screen with default background
        $this->canvas->clear($this->getColorFromAttribute(0));

        $charWidth = 8;
        $charHeight = 16;

        // Group by color for batch rendering
        $bgRectsByColor = [];
        $fgCharsByColor = [];

        foreach ($this->textBuffer as $row => $cols) {
            foreach ($cols as $col => $cell) {
                // Handle both old format (string) and new format (array with char/attr)
                if (is_array($cell)) {
                    $char = $cell['char'];
                    $attr = $cell['attr'];
                } else {
                    $char = $cell;
                    $attr = $this->currentAttribute;
                }

                $x = $col * $charWidth;
                $y = $row * $charHeight;
                $bgColor = ($attr >> 4) & 0x0F;
                $fgColor = $attr & 0x0F;

                // Group backgrounds by color
                if (!isset($bgRectsByColor[$bgColor])) {
                    $bgRectsByColor[$bgColor] = [];
                }
                $bgRectsByColor[$bgColor][] = ['x' => $x, 'y' => $y, 'w' => $charWidth, 'h' => $charHeight];

                // Group foreground chars by color (skip spaces)
                if ($char !== ' ' && $char !== "\0") {
                    if (!isset($fgCharsByColor[$fgColor])) {
                        $fgCharsByColor[$fgColor] = [];
                    }
                    $fgCharsByColor[$fgColor][] = ['x' => $x, 'y' => $y, 'char' => $char];
                }
            }
        }

        // Batch draw backgrounds by color
        foreach ($bgRectsByColor as $colorIndex => $rects) {
            $this->canvas->rectBatch($rects, $this->getColorFromAttribute($colorIndex));
        }

        // Batch draw foreground characters by color
        foreach ($fgCharsByColor as $colorIndex => $chars) {
            $this->canvas->textBatch($chars, $this->getColorFromAttribute($colorIndex));
        }

        // Present to screen
        $this->canvas->present();
        $this->dirty = false;
        $this->lastRedrawTime = microtime(true);
    }

    /**
     * Mark screen as needing redraw.
     */
    protected function markDirty(): void
    {
        $this->dirty = true;
    }

    /**
     * Flush screen if dirty.
     * Rate limiting is applied to avoid excessive redraws, but dirty screens
     * are always flushed to ensure text visibility.
     */
    public function flushIfNeeded(): void
    {
        // Process SDL events (window close, Cmd+Q, etc.)
        if (!$this->window->processEvents()) {
            throw new HaltException('Window closed by user');
        }

        // Flush graphics mode (full redraw from framebuffer)
        if ($this->useFramebuffer && $this->graphicsDirty) {
            $this->flushGraphicsBuffer();
            return;
        }

        if (!empty($this->dotBuffer)) {
            $this->flushDotBuffer();
            return;
        }

        // Flush text mode changes
        if (!$this->dirty) {
            return;
        }

        // Always redraw if dirty - rate limiting happens naturally
        // through the game loop's timing
        $this->redrawScreen();
    }

    public function dot(int $x, int $y, ColorInterface $color): void
    {
        if (!$this->useFramebuffer) {
            $windowColor = new Color($color->red(), $color->green(), $color->blue());
            $this->dotBuffer[] = [
                'x' => $x * $this->pixelSize,
                'y' => $y * $this->pixelSize,
                'color' => $windowColor,
            ];
            return;
        }

        if ($x < 0 || $y < 0 || $x >= $this->graphicsWidth || $y >= $this->graphicsHeight) {
            $this->ensureGraphicsBufferSize($x + 1, $y + 1);
            if ($x < 0 || $y < 0 || $x >= $this->graphicsWidth || $y >= $this->graphicsHeight) {
                return;
            }
        }

        $packed = ($color->red() << 16) | ($color->green() << 8) | $color->blue();
        $index = ($y * $this->graphicsWidth) + $x;
        if (($this->graphicsBuffer[$index] ?? 0) === $packed) {
            return;
        }

        $this->graphicsBuffer[$index] = $packed;
        $this->graphicsDirty = true;
    }

    /**
     * Flush buffered dots to the canvas and present.
     */
    protected function flushDotBuffer(): void
    {
        if (empty($this->dotBuffer)) {
            return;
        }

        if ($this->graphicsNeedsClear) {
            $this->canvas->clear($this->getColorFromAttribute(0));
            $this->graphicsNeedsClear = false;
        }

        $size = $this->pixelSize;
        $batches = [];
        foreach ($this->dotBuffer as $dot) {
            $color = $dot['color'];
            $packed = ($color->red() << 16) | ($color->green() << 8) | $color->blue();
            if (!isset($batches[$packed])) {
                $batches[$packed] = ['color' => $color, 'rects' => []];
            }
            $batches[$packed]['rects'][] = [
                'x' => $dot['x'],
                'y' => $dot['y'],
                'w' => $size,
                'h' => $size,
            ];
        }

        foreach ($batches as $batch) {
            $this->canvas->rectBatch($batch['rects'], $batch['color']);
        }

        $this->canvas->present();
        $this->dotBuffer = [];
    }

    /**
     * Flush framebuffer to the canvas and present.
     */
    protected function flushGraphicsBuffer(): void
    {
        if (!$this->graphicsDirty) {
            return;
        }

        if ($this->graphicsWidth <= 0 || $this->graphicsHeight <= 0) {
            $this->graphicsDirty = false;
            return;
        }

        $this->ensureGraphicsTexture();
        $count = $this->graphicsWidth * $this->graphicsHeight;
        $this->ensureGraphicsPixelBuffer($count);
        $pixelBuffer = $this->graphicsPixelBuffer;
        if ($pixelBuffer === null) {
            $this->graphicsDirty = false;
            return;
        }

        for ($index = 0; $index < $count; $index++) {
            $packed = $this->graphicsBuffer[$index] ?? 0;
            $pixelBuffer[$index] = 0xFF000000 | ($packed & 0xFFFFFF);
        }

        $this->canvas->updateTexture($this->graphicsTexture, $pixelBuffer, $this->graphicsWidth * 4);
        $this->canvas->renderTexture($this->graphicsTexture);
        $this->canvas->present();
        $this->graphicsDirty = false;
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

        $this->markDirty();
    }

    public function writeCharAt(int $row, int $col, string $char, ?int $attribute = null): void
    {
        // Write character at specific position (for video memory writes)
        $attr = $attribute ?? $this->currentAttribute;

        if (!isset($this->textBuffer[$row])) {
            $this->textBuffer[$row] = [];
        }
        $this->textBuffer[$row][$col] = ['char' => $char, 'attr' => $attr];

        $this->markDirty();
    }

    public function clear(): void
    {
        $this->textBuffer = [];
        $this->prevBuffer = [];
        $this->resetCursor();
        $this->canvas->clear($this->getColorFromAttribute(0));
        $this->canvas->present();
        $this->dotBuffer = [];
        $this->resetGraphicsBuffer();
        $this->graphicsNeedsClear = true;
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
        $this->markDirty();
    }

    public function scrollUpWindow(
        int $topRow,
        int $leftCol,
        int $bottomRow,
        int $rightCol,
        int $lines,
        int $attribute
    ): void {
        $width = $rightCol - $leftCol + 1;
        $height = $bottomRow - $topRow + 1;
        if ($width <= 0 || $height <= 0) {
            return;
        }

        if ($lines <= 0 || $lines >= $height) {
            $this->fillArea($topRow, $leftCol, $width, $height, $attribute);
            return;
        }

        for ($row = $topRow; $row <= $bottomRow - $lines; $row++) {
            $srcRow = $row + $lines;
            $srcCols = $this->textBuffer[$srcRow] ?? [];
            $destCols = $this->textBuffer[$row] ?? [];
            for ($col = $leftCol; $col <= $rightCol; $col++) {
                if (isset($srcCols[$col])) {
                    $destCols[$col] = $srcCols[$col];
                } elseif (isset($destCols[$col])) {
                    unset($destCols[$col]);
                }
            }

            if ($destCols === []) {
                unset($this->textBuffer[$row]);
            } else {
                $this->textBuffer[$row] = $destCols;
            }
        }

        for ($row = $bottomRow - $lines + 1; $row <= $bottomRow; $row++) {
            if (!isset($this->textBuffer[$row])) {
                $this->textBuffer[$row] = [];
            }
            for ($col = $leftCol; $col <= $rightCol; $col++) {
                $this->textBuffer[$row][$col] = ['char' => ' ', 'attr' => $attribute];
            }
        }

        $this->markDirty();
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

    protected function resetGraphicsBuffer(): void
    {
        if ($this->videoTypeInfo->isTextMode) {
            $this->graphicsWidth = 0;
            $this->graphicsHeight = 0;
            $this->graphicsBuffer = [];
            $this->graphicsDirty = false;
            $this->resetGraphicsTexture();
            return;
        }

        $this->graphicsWidth = $this->videoTypeInfo->width;
        $this->graphicsHeight = $this->videoTypeInfo->height;
        $size = $this->graphicsWidth * $this->graphicsHeight;
        $this->graphicsBuffer = $size > 0 ? array_fill(0, $size, 0) : [];
        $this->graphicsDirty = false;
        $this->graphicsNeedsClear = true;
        $this->ensureGraphicsTexture();
        $this->ensureGraphicsPixelBuffer($size);
    }

    protected function ensureGraphicsBufferSize(int $minWidth, int $minHeight): void
    {
        $newWidth = max($this->graphicsWidth, $minWidth);
        $newHeight = max($this->graphicsHeight, $minHeight);
        if ($newWidth <= 0 || $newHeight <= 0) {
            return;
        }
        if ($newWidth === $this->graphicsWidth && $newHeight === $this->graphicsHeight) {
            return;
        }

        $newSize = $newWidth * $newHeight;
        $newBuffer = array_fill(0, $newSize, 0);
        if ($this->graphicsWidth > 0 && $this->graphicsHeight > 0 && !empty($this->graphicsBuffer)) {
            $copyWidth = min($this->graphicsWidth, $newWidth);
            $copyHeight = min($this->graphicsHeight, $newHeight);
            for ($y = 0; $y < $copyHeight; $y++) {
                $oldOffset = $y * $this->graphicsWidth;
                $newOffset = $y * $newWidth;
                for ($x = 0; $x < $copyWidth; $x++) {
                    $newBuffer[$newOffset + $x] = $this->graphicsBuffer[$oldOffset + $x] ?? 0;
                }
            }
        }

        $this->graphicsWidth = $newWidth;
        $this->graphicsHeight = $newHeight;
        $this->graphicsBuffer = $newBuffer;
        if ($this->useFramebuffer) {
            $this->ensureGraphicsTexture();
            $this->ensureGraphicsPixelBuffer($newSize);
        }
    }

    protected function ensureGraphicsTexture(): void
    {
        if (!$this->useFramebuffer || $this->graphicsWidth <= 0 || $this->graphicsHeight <= 0) {
            return;
        }

        if (
            $this->graphicsTexture !== null
            && $this->graphicsTextureWidth === $this->graphicsWidth
            && $this->graphicsTextureHeight === $this->graphicsHeight
        ) {
            return;
        }

        $this->resetGraphicsTexture();
        $this->graphicsTexture = $this->canvas->createStreamingTexture(
            $this->graphicsWidth,
            $this->graphicsHeight,
        );
        $this->graphicsTextureWidth = $this->graphicsWidth;
        $this->graphicsTextureHeight = $this->graphicsHeight;
    }

    protected function resetGraphicsTexture(): void
    {
        if ($this->graphicsTexture !== null) {
            $this->canvas->destroyTexture($this->graphicsTexture);
            $this->graphicsTexture = null;
        }
        $this->graphicsTextureWidth = 0;
        $this->graphicsTextureHeight = 0;
        $this->graphicsPixelBuffer = null;
        $this->graphicsPixelBufferSize = 0;
    }

    protected function ensureGraphicsPixelBuffer(int $size): void
    {
        if (!$this->useFramebuffer || $size <= 0) {
            return;
        }

        if ($this->graphicsPixelBuffer !== null && $this->graphicsPixelBufferSize >= $size) {
            return;
        }

        $this->graphicsPixelBuffer = $this->canvas->createPixelBuffer($size);
        $this->graphicsPixelBufferSize = $size;
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

    /**
     * Get current mouse position and button state
     *
     * @return array{x: int, y: int, buttons: int}
     */
    public function getMouseState(): array
    {
        return $this->window->getMouseState();
    }

    /**
     * Check if a specific key is currently pressed
     *
     * @param \PHPMachineEmulator\Display\Window\SDLScancode $scancode SDL scancode
     * @return bool
     */
    public function isKeyPressed(\PHPMachineEmulator\Display\Window\SDLScancode $scancode): bool
    {
        return $this->window->isKeyPressed($scancode);
    }

    /**
     * Poll for a single key press and return BIOS key code
     *
     * @return int|null AX value (AH=scan code, AL=ASCII) or null if no key pressed
     */
    public function pollKeyPress(): ?int
    {
        return $this->window->pollKeyPress();
    }

    /**
     * Check if shift key is currently pressed
     *
     * @return bool
     */
    public function isShiftPressed(): bool
    {
        return $this->window->isShiftPressed();
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
        $this->canvas->clear($this->getColorFromAttribute(0));
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
        $this->canvas->clear($this->getColorFromAttribute(0));
        $this->canvas->present();
    }
}
