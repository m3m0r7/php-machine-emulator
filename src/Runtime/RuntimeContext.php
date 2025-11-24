<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

class RuntimeContext implements RuntimeContextInterface
{
    private bool $operandSizeOverride = false;
    private bool $addressSizeOverride = false;
    private bool $protectedMode = false;
    private array $gdtr = ['base' => 0, 'limit' => 0];
    private array $idtr = ['base' => 0, 'limit' => 0];

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
        if ($this->operandSizeOverride) {
            return $this->shouldUse32bit(false) ? 32 : 16;
        }
        return $this->protectedMode ? 32 : 16;
    }

    public function setProtectedMode(bool $enabled): void
    {
        $this->protectedMode = $enabled;
    }

    public function isProtectedMode(): bool
    {
        return $this->protectedMode;
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
        if ($this->addressSizeOverride) {
            return $this->shouldUse32bitAddress(false) ? 32 : 16;
        }
        return $this->protectedMode ? 32 : 16;
    }

    public function clearTransientOverrides(): void
    {
        $this->operandSizeOverride = false;
        $this->addressSizeOverride = false;
    }

    public function setGdtr(int $base, int $limit): void
    {
        $this->gdtr = ['base' => $base, 'limit' => $limit];
    }

    public function gdtr(): array
    {
        return $this->gdtr;
    }

    public function setIdtr(int $base, int $limit): void
    {
        $this->idtr = ['base' => $base, 'limit' => $limit];
    }

    public function idtr(): array
    {
        return $this->idtr;
    }
}
