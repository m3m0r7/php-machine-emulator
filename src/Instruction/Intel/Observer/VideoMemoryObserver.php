<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryObserver implements MemoryAccessorObserverInterface
{
    protected ?ScreenWriterInterface $writer = null;

    /** @var array<int, array{x: int, y: int, color: int}> Buffered pixel writes */
    protected array $pixelBuffer = [];

    /** @var int Maximum pixels to buffer before flush */
    protected const BUFFER_SIZE = 512;

    /** @var float Last flush time */
    protected float $lastFlushTime = 0.0;

    /** @var float Minimum interval between flushes (seconds) */
    protected const FLUSH_INTERVAL = 5000;

    public function addressRange(): ?array
    {
        return [
            'min' => VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED,
            'max' => VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED,
        ];
    }

    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool
    {
        // Text mode video memory (0xB8000-0xBFFFF): always match for character output
        if ($address >= 0xB8000 && $address < 0xC0000) {
            return true;
        }

        // Graphics mode (0xA0000-0xB7FFF): use original ES:DI check
        // In protected mode, use EDI directly (segment base is defined by GDT, usually 0)
        // In real mode, use ES:DI
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $edi = $runtime
                ->memoryAccessor()
                ->fetch(
                    ($runtime->register())::addressBy(RegisterType::EDI),
                )
                ->asByte();
            return $address === $edi;
        }

        // Real mode: ES:DI
        $esBase = $runtime
            ->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::ES),
            )
            ->asByte() << 4;

        $di = $runtime
            ->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::EDI),
            )
            ->asByte();

        $linear = $di + $esBase;

        return $address === $linear;
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): void
    {
        // Text mode video memory starts at 0xB8000
        // Each character is 2 bytes: [char code][attribute]
        // Only process character bytes (even offsets from 0xB8000)
        $textModeBase = 0xB8000;
        if ($address >= $textModeBase && $address < 0xC0000) {
            $offset = $address - $textModeBase;
            // Only process character bytes (even offsets), skip attribute bytes (odd offsets)
            if (($offset % 2) === 0 && $nextValue !== null && $nextValue >= 0x20 && $nextValue < 0x7F) {
                // This is a printable character - output it via IO
                $runtime->option()->IO()->output()->write(chr($nextValue));
            }
            // Attribute byte or non-printable - ignore
            return;
        }

        // Graphics mode handling (0xA0000-0xB7FFF)
        $videoSettingAddress = $runtime
            ->memoryAccessor()
            ->fetch(
                $runtime->video()
                    ->videoTypeFlagAddress(),
            )
            ->asBytesBySize(64);

        $width = ($videoSettingAddress >> 48) & 0xFFFF;
        $videoType = $videoSettingAddress & 0xFF;

        $videoTypeInfo = $runtime->video()->supportedVideoModes()[$videoType];

        $width = $width === 0 ? $videoTypeInfo->width : $width;

        $this->writer ??= $runtime
            ->context()
            ->screen()
            ->screenWriter();

        $textColor = $nextValue & 0b00001111;

        // Calculate x, y from video memory offset
        $videoMemoryOffset = $address - VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED;
        $x = $videoMemoryOffset % $width;
        $y = (int) ($videoMemoryOffset / $width);

        // Buffer the pixel write instead of immediate rendering
        $this->pixelBuffer[] = [
            'x' => $x,
            'y' => $y,
            'color' => $textColor & 0b1111,
        ];

        // Flush if buffer is full or enough time has passed
        $now = microtime(true);
        if (count($this->pixelBuffer) >= self::BUFFER_SIZE
            || ($now - $this->lastFlushTime) >= self::FLUSH_INTERVAL
        ) {
            $this->flushBuffer();
        }
    }

    /**
     * Flush buffered pixels to the screen.
     */
    public function flushBuffer(): void
    {
        if ($this->writer === null || empty($this->pixelBuffer)) {
            return;
        }

        foreach ($this->pixelBuffer as $pixel) {
            $this->writer->dot(
                $pixel['x'],
                $pixel['y'],
                Color::fromANSI($pixel['color']),
            );
        }

        $this->writer->flushIfNeeded();
        $this->pixelBuffer = [];
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Force flush remaining pixels (call at frame end or shutdown).
     */
    public function forceFlush(): void
    {
        $this->flushBuffer();
    }
}
