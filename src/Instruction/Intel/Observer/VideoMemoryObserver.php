<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;
use PHPMachineEmulator\Display\Writer\TerminalScreenWriter;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryObserver implements MemoryAccessorObserverInterface
{
    protected ?ScreenWriterInterface $writer = null;
    protected int $previousEDI = -1;

    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool
    {
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

        return $address === $linear &&
            $linear >= VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED &&
            $linear <= VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED;
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): void
    {
        $di = $runtime->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::EDI),
            )
            ->asByte();

        $diff = $di - $this->previousEDI - 1;
        $this->previousEDI = $di;

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

        $this->writer ??= new TerminalScreenWriter($runtime, $videoTypeInfo);

        $textColor = $nextValue & 0b00001111;
        $backgroundColor = ($nextValue & 0b11110000) >> 4;

        for ($i = 0; $i < $diff; $i++) {
            $this->writer
                ->dot(Color::asBlack());
        }

        if ($di > 0 && ($di % $width) === 0) {
            $this->writer
                ->newline();
        }

        $this->writer
            ->dot(
                Color::fromANSI(
                    $textColor & 0b1111,
                ),
            );
    }
}
