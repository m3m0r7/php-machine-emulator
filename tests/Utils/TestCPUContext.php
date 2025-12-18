<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Instruction\Intel\x86\ApicState;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;
use PHPMachineEmulator\Instruction\Intel\x86\Cmos;
use PHPMachineEmulator\Instruction\Intel\x86\KeyboardController;
use PHPMachineEmulator\Instruction\Intel\x86\PicState;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\IterationContext;
use PHPMachineEmulator\Runtime\IterationContextInterface;
use PHPMachineEmulator\Runtime\RuntimeCPUContextInterface;

class TestCPUContext implements RuntimeCPUContextInterface
{
    private bool $operandSizeOverride = false;
    private bool $addressSizeOverride = false;
    private bool $protectedMode = false;
    private bool $longMode = false;
    private bool $compatibilityMode = false;
    private int $defaultOperandSize = 32;
    private int $defaultAddressSize = 32;
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
    private int $rex = 0; // lower 4 bits (WRXB)
    private bool $hasRex = false;
    private ?RegisterType $segmentOverride = null;
    private PicState $picState;
    private ApicState $apicState;
    private KeyboardController $keyboardController;
    private Cmos $cmos;
    private Pit $pit;
    private IterationContextInterface $iterationContext;
    /**
     * XMM register file (XMM0-XMM15), stored as 4x32-bit dwords.
     *
     * @var array<int, array{int,int,int,int}>
     */
    private array $xmm = [];

    /**
     * MXCSR (SSE control/status).
     */
    private int $mxcsr = 0x1F80;

    public function __construct()
    {
        $this->apicState = new ApicState();
        $this->picState = new PicState($this->apicState);
        $this->keyboardController = new KeyboardController($this->picState);
        $this->cmos = new Cmos();
        $this->pit = new Pit();
        $this->iterationContext = new IterationContext();
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
        $this->defaultOperandSize = match ($size) {
            64 => 64,
            32 => 32,
            default => 16,
        };
    }

    public function defaultOperandSize(): int
    {
        return $this->defaultOperandSize;
    }

    public function shouldUse32bit(bool $consume = true): bool
    {
        // In 64-bit mode, REX.W takes precedence (operand size becomes 64, not 32)
        if ($this->longMode && !$this->compatibilityMode && $this->rexW()) {
            return false;
        }

        $override = $consume ? $this->consumeOperandSizeOverride() : $this->operandSizeOverride;
        $default32 = $this->defaultOperandSize === 32;
        return $override ? !$default32 : $default32;
    }

    public function shouldUse16bit(bool $consume = true): bool
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // In 64-bit mode, 0x66 prefix toggles between 32 and 16 when REX.W=0
            $override = $consume ? $this->consumeOperandSizeOverride() : $this->operandSizeOverride;
            return $override && !$this->rexW();
        }

        return !$this->shouldUse32bit($consume);
    }

    public function operandSize(): int
    {
        if ($this->longMode && !$this->compatibilityMode) {
            if ($this->rexW()) {
                return 64;
            }
            if ($this->operandSizeOverride) {
                return 16;
            }
            return 32;
        }

        return $this->shouldUse32bit(false) ? 32 : 16;
    }

    public function setProtectedMode(bool $enabled): void
    {
        $this->protectedMode = $enabled;
        if (!$enabled) {
            // Real mode defaults to 16-bit and is not in long/compat mode.
            $this->defaultOperandSize = 16;
            $this->defaultAddressSize = 16;
            $this->longMode = false;
            $this->compatibilityMode = false;
            $this->clearRex();
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
        $this->defaultAddressSize = match ($size) {
            64 => 64,
            32 => 32,
            default => 16,
        };
    }

    public function defaultAddressSize(): int
    {
        return $this->defaultAddressSize;
    }

    public function shouldUse32bitAddress(bool $consume = true): bool
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // In 64-bit mode, 0x67 prefix toggles to 32-bit addressing
            $override = $consume ? $this->consumeAddressSizeOverride() : $this->addressSizeOverride;
            return $override;
        }

        $override = $consume ? $this->consumeAddressSizeOverride() : $this->addressSizeOverride;
        $default32 = $this->defaultAddressSize === 32;
        return $override ? !$default32 : $default32;
    }

    public function shouldUse16bitAddress(bool $consume = true): bool
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // 16-bit addressing is not available in 64-bit mode
            return false;
        }

        return !$this->shouldUse32bitAddress($consume);
    }

    public function addressSize(): int
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // 64-bit mode: default 64-bit, 0x67 gives 32-bit
            return $this->addressSizeOverride ? 32 : 64;
        }

        return $this->shouldUse32bitAddress(false) ? 32 : 16;
    }

    public function clearTransientOverrides(): void
    {
        $this->operandSizeOverride = false;
        $this->addressSizeOverride = false;
        $this->segmentOverride = null;
        $this->clearRex();
    }

    public function setSegmentOverride(?RegisterType $segment): void
    {
        $this->segmentOverride = $segment;
    }

    public function segmentOverride(): ?RegisterType
    {
        return $this->segmentOverride;
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
        $baseValue = $this->longMode ? $base : ($base & 0xFFFFFFFF);
        $this->taskRegister = [
            'selector' => $selector & 0xFFFF,
            'base' => $baseValue,
            'limit' => $limit & 0xFFFFFFFF,
        ];
    }

    public function taskRegister(): array
    {
        return $this->taskRegister;
    }

    public function setLdtr(int $selector, int $base, int $limit): void
    {
        $baseValue = $this->longMode ? $base : ($base & 0xFFFFFFFF);
        $this->ldtr = [
            'selector' => $selector & 0xFFFF,
            'base' => $baseValue,
            'limit' => $limit & 0xFFFFFFFF,
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

    public function pit(): Pit
    {
        return $this->pit;
    }

    // ========================================
    // 64-bit mode support
    // ========================================

    public function shouldUse64bit(bool $consume = true): bool
    {
        if (!$this->longMode || $this->compatibilityMode) {
            return false;
        }
        // In 64-bit mode, REX.W forces 64-bit operand size
        return $this->rexW();
    }

    public function shouldUse64bitAddress(bool $consume = true): bool
    {
        if (!$this->longMode || $this->compatibilityMode) {
            return false;
        }
        // In 64-bit mode, default address size is 64-bit
        $override = $consume ? $this->consumeAddressSizeOverride() : $this->addressSizeOverride;
        return !$override;
    }

    public function setLongMode(bool $enabled): void
    {
        $this->longMode = $enabled;
        if ($enabled) {
            $this->protectedMode = true;
            // Long mode defaults: 32-bit operands, 64-bit addresses
            $this->defaultOperandSize = 32;
            $this->defaultAddressSize = 64;
        }
    }

    public function isLongMode(): bool
    {
        return $this->longMode;
    }

    public function setCompatibilityMode(bool $enabled): void
    {
        $this->compatibilityMode = $enabled;
        if ($enabled) {
            $this->longMode = true;
            $this->protectedMode = true;
            // Compatibility mode: 32-bit operands and addresses
            $this->defaultOperandSize = 32;
            $this->defaultAddressSize = 32;
        }
    }

    public function isCompatibilityMode(): bool
    {
        return $this->compatibilityMode;
    }

    public function setRex(int $rex): void
    {
        $this->rex = $rex & 0x0F;
        $this->hasRex = true;
    }

    public function rex(): int
    {
        return $this->rex;
    }

    public function hasRex(): bool
    {
        return $this->hasRex;
    }

    public function rexW(): bool
    {
        return $this->hasRex && (($this->rex & 0x08) !== 0);
    }

    public function rexR(): bool
    {
        return $this->hasRex && (($this->rex & 0x04) !== 0);
    }

    public function rexX(): bool
    {
        return $this->hasRex && (($this->rex & 0x02) !== 0);
    }

    public function rexB(): bool
    {
        return $this->hasRex && (($this->rex & 0x01) !== 0);
    }

    public function clearRex(): void
    {
        $this->rex = 0;
        $this->hasRex = false;
    }

    public function iteration(): IterationContextInterface
    {
        return $this->iterationContext;
    }

    private int $currentInstructionPointer = 0;

    public function currentInstructionPointer(): int
    {
        return $this->currentInstructionPointer;
    }

    public function setCurrentInstructionPointer(int $ip): void
    {
        $this->currentInstructionPointer = $ip;
    }

    // ========================================
    // Big Real Mode (Unreal Mode) support
    // ========================================

    private array $segmentDescriptorCache = [];

    public function cacheSegmentDescriptor(RegisterType $segment, array $descriptor): void
    {
        $this->segmentDescriptorCache[$segment->name] = $descriptor;
    }

    public function getCachedSegmentDescriptor(RegisterType $segment): ?array
    {
        return $this->segmentDescriptorCache[$segment->name] ?? null;
    }

    public function hasExtendedSegmentLimit(RegisterType $segment): bool
    {
        $cached = $this->getCachedSegmentDescriptor($segment);
        if ($cached === null) {
            return false;
        }
        return isset($cached['limit']) && $cached['limit'] > 0xFFFF;
    }

    public function getXmm(int $index): array
    {
        $this->initXmm();
        $index &= 0xF;
        return $this->xmm[$index];
    }

    public function setXmm(int $index, array $value): void
    {
        $this->initXmm();
        $index &= 0xF;
        $this->xmm[$index] = [
            $value[0] & 0xFFFFFFFF,
            $value[1] & 0xFFFFFFFF,
            $value[2] & 0xFFFFFFFF,
            $value[3] & 0xFFFFFFFF,
        ];
    }

    public function mxcsr(): int
    {
        return $this->mxcsr & 0xFFFFFFFF;
    }

    public function setMxcsr(int $mxcsr): void
    {
        $this->mxcsr = $mxcsr & 0xFFFFFFFF;
    }

    private function initXmm(): void
    {
        if ($this->xmm !== []) {
            return;
        }
        $this->xmm = array_fill(0, 16, [0, 0, 0, 0]);
    }
}
