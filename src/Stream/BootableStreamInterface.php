<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

/**
 * Interface for bootable streams that can be loaded into memory.
 */
interface BootableStreamInterface extends StreamReaderIsProxyableInterface
{
    /**
     * Get the load address where boot data should be placed in memory.
     */
    public function loadAddress(): int;

    /**
     * Get the load segment for CS register initialization.
     */
    public function loadSegment(): int;

    /**
     * Get the total size of boot data.
     */
    public function fileSize(): int;
}
