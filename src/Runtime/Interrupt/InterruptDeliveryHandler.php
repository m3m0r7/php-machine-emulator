<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Handles delivery of pending interrupts from APIC and PIC.
 */
class InterruptDeliveryHandler implements InterruptDeliveryHandlerInterface
{
    private const INT_OPCODE = 0xCD;

    public function __construct(
        private readonly ArchitectureProviderInterface $architectureProvider,
    ) {}

    public function deliverPendingInterrupts(RuntimeInterface $runtime): bool
    {
        $cpuContext = $runtime->context()->cpu();
        $memoryAccessor = $runtime->memoryAccessor();

        $deliveryBlocked = $cpuContext->consumeInterruptDeliveryBlock();
        if ($deliveryBlocked || !$memoryAccessor->shouldInterruptFlag()) {
            return false;
        }

        // Try APIC first
        if ($this->deliverApicInterrupt($runtime)) {
            return true;
        }

        // Then try PIC
        return $this->deliverPicInterrupt($runtime);
    }

    private function deliverApicInterrupt(RuntimeInterface $runtime): bool
    {
        $apic = $runtime->context()->cpu()->apicState();
        if (!$apic->apicEnabled()) {
            return false;
        }

        $vector = $apic->pendingVector();
        if ($vector === null) {
            return false;
        }

        return $this->raiseInterrupt($runtime, $vector);
    }

    private function deliverPicInterrupt(RuntimeInterface $runtime): bool
    {
        $picState = $runtime->context()->cpu()->picState();
        $vector = $picState->pendingVector();

        if ($vector === null) {
            return false;
        }

        return $this->raiseInterrupt($runtime, $vector);
    }

    public function raiseFault(RuntimeInterface $runtime, int $vector, int $ip, ?int $errorCode): bool
    {
        return $this->raiseInterruptWithErrorCode($runtime, $vector, $ip, $errorCode);
    }

    private function raiseInterrupt(RuntimeInterface $runtime, int $vector): bool
    {
        return $this->raiseInterruptWithErrorCode($runtime, $vector, $runtime->streamReader()->offset(), null);
    }

    private function raiseInterruptWithErrorCode(RuntimeInterface $runtime, int $vector, int $ip, ?int $errorCode): bool
    {
        try {
            $handler = $this
                ->architectureProvider
                ->instructionList()
                ->getInstructionByOperationCode(self::INT_OPCODE);
        } catch (\Throwable) {
            return false;
        }

        if (!$handler instanceof Int_) {
            return false;
        }

        $handler->raise($runtime, $vector, $ip, $errorCode);
        return true;
    }
}
