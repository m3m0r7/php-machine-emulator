<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

/**
 * Interface for memory streams with size and capacity management.
 */
interface MemoryStreamInterface extends StreamIsProxyableInterface, StreamReaderIsProxyableInterface, StreamIsCopyableInterface
{
    /**
     * Expand memory if needed to accommodate the given offset.
     */
    public function ensureCapacity(int $requiredOffset): bool;

    /**
     * Get the current allocated memory size.
     */
    public function size(): int;

    /**
     * Get the logical maximum memory size (physical + swap).
     * This is the total addressable memory space.
     */
    public function logicalMaxMemorySize(): int;

    /**
     * Get the physical maximum memory size (without swap).
     * Data up to this size stays in RAM; beyond this goes to temp file.
     */
    public function physicalMaxMemorySize(): int;

    /**
     * Get the swap size.
     */
    public function swapSize(): int;
}
