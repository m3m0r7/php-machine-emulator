<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\Observer;

use PHPMachineEmulator\Instruction\Intel\Service\VideoMemoryService;
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

        // NOTE: Express to write ES register only
        return $address === ($runtime->register())::addressBy(RegisterType::EDI) &&
            $es >= VideoMemoryService::VIDEO_MEMORY_ADDRESS_STARTED && $es <= VideoMemoryService::VIDEO_MEMORY_ADDRESS_ENDED;
    }

    public function observe(RuntimeInterface $runtime, int $address, int|null $value): void
    {
        $di = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EDI)
            ->asByte();

        if ($value & 0x0f !== 0) {
            echo '|';
        } else if ($value === 0x00) {
            echo ' ';
        }
    }
}
