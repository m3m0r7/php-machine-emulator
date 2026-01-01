<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Interrupt;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Handles delivery of pending interrupts from registered interrupt sources.
 */
class InterruptDeliveryHandler implements InterruptDeliveryHandlerInterface
{
    private const INT_OPCODE = 0xCD;
    private const DOUBLE_FAULT_VECTOR = 0x08;

    /** @var InterruptSourceInterface[] */
    private array $sources = [];

    /**
     * Nested exception delivery depth.
     *
     * Used to detect faults that occur while attempting to deliver another exception
     * and to synthesize a double fault / triple fault behavior.
     */
    private int $deliveryDepth = 0;

    public function __construct(
        private readonly ArchitectureProviderInterface $architectureProvider,
    ) {
    }

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
        $this->deliveryDepth++;
        try {
            $handler = $this
                ->architectureProvider
                ->instructionList()
                ->findInstruction(self::INT_OPCODE);
        } catch (\Throwable) {
            $this->deliveryDepth--;
            return false;
        }

        if (!$handler instanceof Int_) {
            $this->deliveryDepth--;
            return false;
        }

        try {
            $handler->raise($runtime, $vector, $ip, $errorCode);
            return true;
        } catch (FaultException $e) {
            // Fault while delivering an interrupt/exception.
            // If this happens while trying to deliver a prior exception, CPU raises #DF.
            $runtime->option()->logger()->error(sprintf(
                'FAULT DURING DELIVERY: vec=0x%02X -> fault=0x%02X err=%s depth=%d',
                $vector & 0xFF,
                $e->vector() & 0xFF,
                $e->errorCode() === null ? 'n/a' : sprintf('0x%04X', $e->errorCode() & 0xFFFF),
                $this->deliveryDepth,
            ));

            // Already delivering #DF, or fault happened while nested: triple fault.
            if (($vector & 0xFF) === self::DOUBLE_FAULT_VECTOR || $this->deliveryDepth > 1) {
                $runtime->option()->logger()->error('TRIPLE FAULT: halting CPU');
                throw new HaltException('Triple fault');
            }

            // Try delivering a double fault (#DF). Error code is always 0.
            try {
                $handler->raise($runtime, self::DOUBLE_FAULT_VECTOR, $ip, 0);
                return true;
            } catch (FaultException $df) {
                $runtime->option()->logger()->error(sprintf(
                    'TRIPLE FAULT: #DF delivery failed (fault=0x%02X err=%s)',
                    $df->vector() & 0xFF,
                    $df->errorCode() === null ? 'n/a' : sprintf('0x%04X', $df->errorCode() & 0xFFFF),
                ));
                throw new HaltException('Triple fault');
            }
        } finally {
            $this->deliveryDepth--;
        }
    }
}
