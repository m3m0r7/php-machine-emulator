<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class RuntimeContext implements RuntimeContextInterface
{
    private bool $operandSizeOverride = false;
    private bool $addressSizeOverride = false;

    public function setOperandSizeOverride(bool $flag = true): void
    {
        $this->operandSizeOverride = $flag;
    }

    public function consumeOperandSizeOverride(): bool
    {
        $flag = $this->operandSizeOverride;
        $this->operandSizeOverride = false;
        return $flag;
    }

    public function shouldUse32bit(bool $consume = true): bool
    {
        return $consume ? $this->consumeOperandSizeOverride() : $this->operandSizeOverride;
    }

    public function shouldUse16bit(bool $consume = true): bool
    {
        return !$this->shouldUse32bit($consume);
    }

    public function operandSize(): int
    {
        return $this->shouldUse32bit(false) ? 32 : 16;
    }

    public function setAddressSizeOverride(bool $flag = true): void
    {
        $this->addressSizeOverride = $flag;
    }

    public function consumeAddressSizeOverride(): bool
    {
        $flag = $this->addressSizeOverride;
        $this->addressSizeOverride = false;
        return $flag;
    }

    public function shouldUse32bitAddress(bool $consume = true): bool
    {
        return $consume ? $this->consumeAddressSizeOverride() : $this->addressSizeOverride;
    }

    public function shouldUse16bitAddress(bool $consume = true): bool
    {
        return !$this->shouldUse32bitAddress($consume);
    }

    public function addressSize(): int
    {
        return $this->shouldUse32bitAddress(false) ? 32 : 16;
    }

    public function clearTransientOverrides(): void
    {
        $this->operandSizeOverride = false;
        $this->addressSizeOverride = false;
    }
}
