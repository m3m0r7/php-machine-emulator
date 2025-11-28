<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Interface for handling interrupt delivery.
 */
interface InterruptDeliveryHandlerInterface
{
    /**
     * Try to deliver pending interrupts.
     *
     * @return bool True if an interrupt was delivered
     */
    public function deliverPendingInterrupts(RuntimeInterface $runtime): bool;

    /**
     * Raise a fault interrupt (e.g., page fault, general protection fault).
     *
     * @return bool True if the fault was handled
     */
    public function raiseFault(RuntimeInterface $runtime, int $vector, int $ip, ?int $errorCode): bool;
}
