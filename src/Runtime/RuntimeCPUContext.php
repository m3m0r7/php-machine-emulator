<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\Intel\x86\ApicState;
use PHPMachineEmulator\Instruction\Intel\x86\Cmos;
use PHPMachineEmulator\Instruction\Intel\x86\KeyboardController;
use PHPMachineEmulator\Instruction\Intel\x86\PicState;

class RuntimeCPUContext implements RuntimeCPUContextInterface
{
    private bool $operandSizeOverride = false;
    private bool $addressSizeOverride = false;
    private bool $protectedMode = false;
    private int $defaultOperandSize = 16;
    private int $defaultAddressSize = 16;
    private array $gdtr = ['base' => 0, 'limit' => 0];
    private array $idtr = ['base' => 0, 'limit' => 0];
    private bool $a20Enabled = false;
    private bool $waitingA20OutputPort = false;
    private bool $pagingEnabled = false;
    private bool $userMode = false;
    private int $cpl = 0;
    private array $taskRegister = ['selector' => 0, 'base' => 0, 'limit' => 0];
    private array $ldtr = ['selector' => 0, 'base' => 0, 'limit' => 0];
    private int $iopl = 0;
    private bool $nt = false;
    private int $interruptDeliveryBlock = 0;
    private bool $memoryMode = false;
    private PicState $picState;
    private ApicState $apicState;
    private KeyboardController $keyboardController;
    private Cmos $cmos;

    public function __construct()
    {
        $this->apicState = new ApicState();
        $this->picState = new PicState($this->apicState);
        $this->keyboardController = new KeyboardController($this->picState);
        $this->cmos = new Cmos();
    }

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

    public function setDefaultOperandSize(int $size): void
    {
        $this->defaultOperandSize = $size === 32 ? 32 : 16;
    }

    public function defaultOperandSize(): int
    {
        return $this->defaultOperandSize;
    }

    public function shouldUse32bit(bool $consume = true): bool
    {
        $override = $consume ? $this->consumeOperandSizeOverride() : $this->operandSizeOverride;
        $default32 = $this->defaultOperandSize === 32;
        return $override ? !$default32 : $default32;
    }

    public function shouldUse16bit(bool $consume = true): bool
    {
        return !$this->shouldUse32bit($consume);
    }

    public function operandSize(): int
    {
        return $this->shouldUse32bit(false) ? 32 : 16;
    }

    public function setProtectedMode(bool $enabled): void
    {
        $this->protectedMode = $enabled;
        if (!$enabled) {
            $this->defaultOperandSize = 16;
            $this->defaultAddressSize = 16;
        }
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

    public function setDefaultAddressSize(int $size): void
    {
        $this->defaultAddressSize = $size === 32 ? 32 : 16;
    }

    public function defaultAddressSize(): int
    {
        return $this->defaultAddressSize;
    }

    public function shouldUse32bitAddress(bool $consume = true): bool
    {
        $override = $consume ? $this->consumeAddressSizeOverride() : $this->addressSizeOverride;
        $default32 = $this->defaultAddressSize === 32;
        return $override ? !$default32 : $default32;
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

    public function enableA20(bool $enabled = true): void
    {
        $this->a20Enabled = $enabled;
    }

    public function isA20Enabled(): bool
    {
        return $this->a20Enabled;
    }

    public function setWaitingA20OutputPort(bool $flag = true): void
    {
        $this->waitingA20OutputPort = $flag;
    }

    public function isWaitingA20OutputPort(): bool
    {
        return $this->waitingA20OutputPort;
    }

    public function setPagingEnabled(bool $enabled): void
    {
        $this->pagingEnabled = $enabled;
    }

    public function isPagingEnabled(): bool
    {
        return $this->pagingEnabled;
    }

    public function setUserMode(bool $user): void
    {
        $this->userMode = $user;
    }

    public function isUserMode(): bool
    {
        return $this->userMode;
    }

    public function setCpl(int $cpl): void
    {
        $this->cpl = $cpl & 0x3;
    }

    public function cpl(): int
    {
        return $this->cpl;
    }

    public function setTaskRegister(int $selector, int $base, int $limit): void
    {
        $this->taskRegister = [
            'selector' => $selector & 0xFFFF,
            'base' => $base & 0xFFFFFFFF,
            'limit' => $limit & 0xFFFF,
        ];
    }

    public function taskRegister(): array
    {
        return $this->taskRegister;
    }

    public function setLdtr(int $selector, int $base, int $limit): void
    {
        $this->ldtr = [
            'selector' => $selector & 0xFFFF,
            'base' => $base & 0xFFFFFFFF,
            'limit' => $limit & 0xFFFF,
        ];
    }

    public function ldtr(): array
    {
        return $this->ldtr;
    }

    public function setIopl(int $iopl): void
    {
        $this->iopl = $iopl & 0x3;
    }

    public function iopl(): int
    {
        return $this->iopl;
    }

    public function setNt(bool $nt): void
    {
        $this->nt = $nt;
    }

    public function nt(): bool
    {
        return $this->nt;
    }

    public function blockInterruptDelivery(int $count = 1): void
    {
        $this->interruptDeliveryBlock = $count;
    }

    public function consumeInterruptDeliveryBlock(): bool
    {
        if ($this->interruptDeliveryBlock > 0) {
            $this->interruptDeliveryBlock--;
            return true;
        }
        return false;
    }

    public function picState(): PicState
    {
        return $this->picState;
    }

    public function apicState(): ApicState
    {
        return $this->apicState;
    }

    public function keyboardController(): KeyboardController
    {
        return $this->keyboardController;
    }

    public function cmos(): Cmos
    {
        return $this->cmos;
    }

    public function setMemoryMode(bool $enabled): void
    {
        $this->memoryMode = $enabled;
    }

    public function isMemoryMode(): bool
    {
        return $this->memoryMode;
    }

    public function memoryModeThreshold(): int
    {
        return 0x10000;
    }

    public function activateMemoryModeIfNeeded(int $address): void
    {
        if ($address >= $this->memoryModeThreshold()) {
            $this->memoryMode = true;
        }
    }
}
