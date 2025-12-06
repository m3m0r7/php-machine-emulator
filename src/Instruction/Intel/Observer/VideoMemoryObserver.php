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
    protected const FLUSH_INTERVAL = 0.016; // ~60 FPS

    public function addressRange(): ?array
    {
        return [
            'min' => VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED,
            'max' => VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED,
        ];
    }

    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool
    {
        // Address range already checked by MemoryAccessor
        // Only check ES:DI match
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
