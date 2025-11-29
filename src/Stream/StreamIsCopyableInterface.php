<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamIsCopyableInterface
{
    /**
     * Copy data from a source stream to this stream.
     *
     * @param StreamReaderInterface $source Source stream to copy from
     * @param int $sourceOffset Offset in source stream to start reading
     * @param int $destOffset Offset in this stream to start writing
     * @param int $size Number of bytes to copy
     */
    public function copy(StreamReaderInterface $source, int $sourceOffset, int $destOffset, int $size): void;
}
