<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class WatchAccessConfig
{
    /**
     * @param array<int,array{start:int,end:int}> $excludeIpRanges
     */
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly int $limit = 64,
        public readonly bool $reads = false,
        public readonly bool $writes = true,
        public readonly ?int $width = null,
        public readonly array $excludeIpRanges = [],
        public readonly ?int $armAfterInt13Lba = null,
        public readonly ?string $source = null,
    ) {
    }
}
