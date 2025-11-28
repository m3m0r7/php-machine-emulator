<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

interface RuntimeCPUContextInterface
{
    public function setOperandSizeOverride(bool $flag = true): void;
    public function consumeOperandSizeOverride(): bool;
    public function setDefaultOperandSize(int $size): void;
    public function defaultOperandSize(): int;
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
    public function setDefaultAddressSize(int $size): void;
    public function defaultAddressSize(): int;
    public function shouldUse32bitAddress(bool $consume = true): bool;
    public function shouldUse16bitAddress(bool $consume = true): bool;
    public function addressSize(): int;
    public function clearTransientOverrides(): void;
    public function enableA20(bool $enabled = true): void;
    public function isA20Enabled(): bool;
    public function setWaitingA20OutputPort(bool $flag = true): void;
    public function isWaitingA20OutputPort(): bool;
    public function setPagingEnabled(bool $enabled): void;
    public function isPagingEnabled(): bool;
    public function setUserMode(bool $user): void;
    public function isUserMode(): bool;
    public function setCpl(int $cpl): void;
    public function cpl(): int;
    public function setTaskRegister(int $selector, int $base, int $limit): void;
    public function taskRegister(): array;
    public function setLdtr(int $selector, int $base, int $limit): void;
    public function ldtr(): array;
    public function setIopl(int $iopl): void;
    public function iopl(): int;
    public function setNt(bool $nt): void;
    public function nt(): bool;
    public function blockInterruptDelivery(int $count = 1): void;
    public function consumeInterruptDeliveryBlock(): bool;
    public function picState(): \PHPMachineEmulator\Instruction\Intel\x86\PicState;
    public function apicState(): \PHPMachineEmulator\Instruction\Intel\x86\ApicState;
    public function keyboardController(): \PHPMachineEmulator\Instruction\Intel\x86\KeyboardController;
    public function cmos(): \PHPMachineEmulator\Instruction\Intel\x86\Cmos;
    public function setMemoryMode(bool $enabled): void;
    public function isMemoryMode(): bool;
    public function memoryModeThreshold(): int;
    public function activateMemoryModeIfNeeded(int $address): void;
}
