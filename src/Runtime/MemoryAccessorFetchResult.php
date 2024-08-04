<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class MemoryAccessorFetchResult implements MemoryAccessorFetchResultInterface
{
    public function __construct(protected int|null $value)
    {
    }

    public function asChar(): string
    {
        if ($this->value === null) {
            return chr(0);
        }
        return chr($this->value);
    }

    public function asByte(): int
    {
        if ($this->value === null) {
            return 0;
        }
        return $this->value;
    }

    public function valueOf(): int|null
    {
        return $this->value;
    }
}
