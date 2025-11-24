<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

interface RuntimeContextInterface
{
    public function setOperandSizeOverride(bool $flag = true): void;
    public function consumeOperandSizeOverride(): bool;
    public function shouldUse32bit(bool $consume = true): bool;
    public function shouldUse16bit(bool $consume = true): bool;
    public function operandSize(): int;
    public function setProtectedMode(bool $enabled): void;
    public function isProtectedMode(): bool;
    public function setGdtr(int $base, int $limit): void;
    public function gdtr(): array;
    public function setIdtr(int $base, int $limit): void;
    public function idtr(): array;
    public function setAddressSizeOverride(bool $flag = true): void;
    public function consumeAddressSizeOverride(): bool;
    public function shouldUse32bitAddress(bool $consume = true): bool;
    public function shouldUse16bitAddress(bool $consume = true): bool;
    public function addressSize(): int;
    public function clearTransientOverrides(): void;
}
