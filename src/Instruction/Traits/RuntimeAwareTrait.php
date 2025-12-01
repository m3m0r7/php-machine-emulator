<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\PHPMachineEmulatorException;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for instruction lists that need runtime access.
 *
 * Provides setRuntime/runtime methods for InstructionListInterface implementations.
 */
trait RuntimeAwareTrait
{
    protected ?RuntimeInterface $runtime = null;

    public function setRuntime(RuntimeInterface $runtime): void
    {
        $this->runtime = $runtime;
    }

    public function runtime(): RuntimeInterface
    {
        return $this->runtime ?? throw new PHPMachineEmulatorException(
            'Runtime not set. Call setRuntime() before accessing runtime.'
        );
    }

    /**
     * Check if runtime is available.
     */
    public function hasRuntime(): bool
    {
        return $this->runtime !== null;
    }

    /**
     * Check if CPU is currently in 64-bit mode (Long Mode).
     */
    protected function isIn64BitMode(): bool
    {
        if ($this->runtime === null) {
            return false;
        }

        return $this->runtime->context()->cpu()->isLongMode()
            && !$this->runtime->context()->cpu()->isCompatibilityMode();
    }

    /**
     * Check if CPU is in protected mode.
     */
    protected function isInProtectedMode(): bool
    {
        if ($this->runtime === null) {
            return false;
        }

        return $this->runtime->context()->cpu()->isProtectedMode();
    }

    /**
     * Check if CPU is in real mode.
     */
    protected function isInRealMode(): bool
    {
        if ($this->runtime === null) {
            return true; // Default to real mode
        }

        return !$this->runtime->context()->cpu()->isProtectedMode();
    }
}
