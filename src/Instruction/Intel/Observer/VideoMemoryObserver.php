<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Display\Pixel\Drawer;
use PHPMachineEmulator\Display\Pixel\DrawerInterface;
use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryObserver implements MemoryAccessorObserverInterface
{
    protected DrawerInterface $drawer;

    public function __construct()
    {
        $this->drawer = new Drawer();
    }

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
        var_dump($value);
        if ($value & 0x0f !== 0) {
            $runtime
                ->option()
                ->IO()
                ->output()
                ->write($this->drawer->dot(Color::asWhite()));
        } else if ($value === 0x00) {
            $runtime
                ->option()
                ->IO()
                ->output()
                ->write($this->drawer->dot(Color::asBlack()));
        }
    }
}
