<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

interface RuntimeCPUContextInterface
{
    // ========================================
    // Operand size control
    // ========================================
    public function setOperandSizeOverride(bool $flag = true): void;
    public function consumeOperandSizeOverride(): bool;
    public function setDefaultOperandSize(int $size): void;
    public function defaultOperandSize(): int;
    public function shouldUse32bit(bool $consume = true): bool;
    public function shouldUse16bit(bool $consume = true): bool;
    public function shouldUse64bit(bool $consume = true): bool;
    public function operandSize(): int;

    // ========================================
    // Address size control
    // ========================================
    public function setAddressSizeOverride(bool $flag = true): void;
    public function consumeAddressSizeOverride(): bool;
    public function setDefaultAddressSize(int $size): void;
    public function defaultAddressSize(): int;
    public function shouldUse32bitAddress(bool $consume = true): bool;
    public function shouldUse16bitAddress(bool $consume = true): bool;
    public function shouldUse64bitAddress(bool $consume = true): bool;
    public function addressSize(): int;

    // ========================================
    // CPU mode control
    // ========================================
    public function setProtectedMode(bool $enabled): void;
    public function isProtectedMode(): bool;
    public function setLongMode(bool $enabled): void;
    public function isLongMode(): bool;
    public function setCompatibilityMode(bool $enabled): void;
    public function isCompatibilityMode(): bool;

    // ========================================
    // REX prefix support (64-bit mode)
    // ========================================
    public function setRex(int $rex): void;
    public function rex(): int;
    public function hasRex(): bool;
    public function rexW(): bool;
    public function rexR(): bool;
    public function rexX(): bool;
    public function rexB(): bool;
    public function clearRex(): void;

    // ========================================
    // Descriptor tables
    // ========================================
    public function setGdtr(int $base, int $limit): void;
    public function gdtr(): array;
    public function setIdtr(int $base, int $limit): void;
    public function idtr(): array;
    public function setTaskRegister(int $selector, int $base, int $limit): void;
    public function taskRegister(): array;
    public function setLdtr(int $selector, int $base, int $limit): void;
    public function ldtr(): array;

    // ========================================
    // Segment override
    // ========================================
    public function setSegmentOverride(?\PHPMachineEmulator\Instruction\RegisterType $segment): void;
    public function segmentOverride(): ?\PHPMachineEmulator\Instruction\RegisterType;

    // ========================================
    // Address line and paging
    // ========================================
    public function clearTransientOverrides(): void;
    public function enableA20(bool $enabled = true): void;
    public function isA20Enabled(): bool;
    public function setWaitingA20OutputPort(bool $flag = true): void;
    public function isWaitingA20OutputPort(): bool;
    public function setPagingEnabled(bool $enabled): void;
    public function isPagingEnabled(): bool;

    // ========================================
    // Privilege and protection
    // ========================================
    public function setUserMode(bool $user): void;
    public function isUserMode(): bool;
    public function setCpl(int $cpl): void;
    public function cpl(): int;
    public function setIopl(int $iopl): void;
    public function iopl(): int;
    public function setNt(bool $nt): void;
    public function nt(): bool;

    // ========================================
    // Interrupt handling
    // ========================================
    public function blockInterruptDelivery(int $count = 1): void;
    public function consumeInterruptDeliveryBlock(): bool;

    // ========================================
    // Hardware state
    // ========================================
    public function picState(): \PHPMachineEmulator\Instruction\Intel\x86\PicState;
    public function apicState(): \PHPMachineEmulator\Instruction\Intel\x86\ApicState;
    public function keyboardController(): \PHPMachineEmulator\Instruction\Intel\x86\KeyboardController;
    public function cmos(): \PHPMachineEmulator\Instruction\Intel\x86\Cmos;
    public function pit(): \PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;

    // ========================================
    // Iteration context (for REP prefix, etc.)
    // ========================================
    public function iteration(): IterationContextInterface;

    // ========================================
    // Instruction pointer for iteration
    // ========================================
    public function currentInstructionPointer(): int;
    public function setCurrentInstructionPointer(int $ip): void;
}
