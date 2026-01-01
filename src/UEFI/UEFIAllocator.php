<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

final class UEFIAllocator
{
    private int $cursor;

    public function __construct(
        private UEFIMemory $mem,
        private int        $base,
        private int        $limit,
    ) {
        $this->cursor = $base;
    }

    public function allocate(int $size, int $align = 8): int
    {
        $size = max(1, $size);
        $align = max(1, $align);
        $addr = ($this->cursor + ($align - 1)) & (~($align - 1));
        $next = $addr + $size;
        if ($next > $this->limit) {
            throw new \RuntimeException('UEFI allocator out of space');
        }
        $this->cursor = $next;
        return $addr;
    }

    public function allocateZeroed(int $size, int $align = 8): int
    {
        $addr = $this->allocate($size, $align);
        $this->mem->writeBytes($addr, str_repeat("\x00", $size));
        return $addr;
    }
}
