<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorObserverInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class VideoMemoryObserver implements MemoryAccessorObserverInterface
{
    public function isMatched(RuntimeInterface $runtime, int $address, int|null $previousValue, int|null $nextValue): bool
    {
        $es = $runtime
            ->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::ES),
            )
            ->asByte();

        return $es >= 0xA000 && $es <= 0xAFFF;
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $value): void
    {
        $di = $runtime
            ->memoryAccessor()
            ->fetch(
                ($runtime->register())::addressBy(RegisterType::EDI),
            )
            ->asByte();

//        var_dump($value, $di);
    }
}
