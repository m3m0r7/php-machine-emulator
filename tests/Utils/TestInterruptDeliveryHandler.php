<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandlerInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class TestInterruptDeliveryHandler implements InterruptDeliveryHandlerInterface
{
    public function deliverPendingInterrupts(RuntimeInterface $runtime): bool
    {
        return false;
    }

    public function raiseFault(RuntimeInterface $runtime, int $vector, int $ip, ?int $errorCode): bool
    {
        return false;
    }
}
