<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Handles delivery of pending interrupts from registered interrupt sources.
 */
class InterruptDeliveryHandler implements InterruptDeliveryHandlerInterface
{
    private const INT_OPCODE = 0xCD;

    /** @var InterruptSourceInterface[] */
    private array $sources = [];

    public function __construct(
        private readonly ArchitectureProviderInterface $architectureProvider,
    ) {}

    /**
     * Register an interrupt source.
     * Sources are checked in registration order (first registered = highest priority).
     */
    public function register(InterruptSourceInterface $source): self
    {
        $this->sources[] = $source;
        return $this;
    }

    public function deliverPendingInterrupts(RuntimeInterface $runtime): bool
    {
        $cpuContext = $runtime->context()->cpu();
        $memoryAccessor = $runtime->memoryAccessor();

        $deliveryBlocked = $cpuContext->consumeInterruptDeliveryBlock();
        if ($deliveryBlocked || !$memoryAccessor->shouldInterruptFlag()) {
            return false;
        }

        // Try each interrupt source in priority order
        foreach ($this->sources as $source) {
            if (!$source->isEnabled($runtime)) {
                continue;
            }

            $vector = $source->pendingVector($runtime);
            if ($vector !== null) {
                return $this->raiseInterrupt($runtime, $vector);
            }
        }

        return false;
    }

    public function raiseFault(RuntimeInterface $runtime, int $vector, int $ip, ?int $errorCode): bool
    {
        return $this->raiseInterruptWithErrorCode($runtime, $vector, $ip, $errorCode);
    }

    private function raiseInterrupt(RuntimeInterface $runtime, int $vector): bool
    {
        return $this->raiseInterruptWithErrorCode($runtime, $vector, $runtime->memory()->offset(), null);
    }

    private function raiseInterruptWithErrorCode(RuntimeInterface $runtime, int $vector, int $ip, ?int $errorCode): bool
    {
        try {
            [$handler, ] = $this
                ->architectureProvider
                ->instructionList()
                ->findInstruction(self::INT_OPCODE);
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
