<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Pixel\Drawer;
use PHPMachineEmulator\Display\Pixel\DrawerInterface;
use PHPMachineEmulator\Display\Writer\TerminalScreenWriter;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryObserver implements MemoryAccessorObserverInterface
{
    protected ?DrawerInterface $drawer = null;
    protected int $previousEDI = -1;

    public function shouldMatch(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool
    {
        $es = $runtime
            ->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::ES),
            )
            ->asByte();

        $di = $runtime
            ->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::EDI),
            )
            ->asByte();

        return $address === ($di + $es) &&
            ($di + $es) >= VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED &&
            ($di + $es) <= VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED;
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $value): void
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
            ->asByte();

        $videoType = $videoSettingAddress & 0b11111111;

        $videoTypeInfo = $runtime->video()->supportedVideoModes()[$videoType];

        $this->drawer ??= new Drawer(new TerminalScreenWriter($runtime, $videoTypeInfo));

        $textColor = $value & 0b00001111;
        $backgroundColor = ($value & 0b01110000) >> 4;
        $blinkBit = ($value & 0b10000000) >> 7;

        for ($i = 0; $i < $diff; $i++) {
            $this->drawer
                ->dot(Color::asBlack());
        }

        if ($di > 0 && ($di % ($videoTypeInfo->width)) === 0) {
            $this->drawer
                ->newline();
        }

        if (($backgroundColor & 0xF) !== 0) {
            $this->drawer
                ->dot(Color::asWhite());
        }

        if ($backgroundColor === 0) {
            $this->drawer
                ->dot(Color::asBlack());
        }
    }
}
