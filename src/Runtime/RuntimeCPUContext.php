<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Instruction\Intel\x86\ApicState;
use PHPMachineEmulator\Instruction\Intel\x86\Cmos;
use PHPMachineEmulator\Instruction\Intel\x86\KeyboardController;
use PHPMachineEmulator\Instruction\Intel\x86\PicState;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * CPU context for x86/x64 emulation.
 *
 * Supports three CPU modes:
 * - Real Mode: 16-bit default operand/address size
 * - Protected Mode: 16 or 32-bit depending on segment descriptor D bit
 * - Long Mode (64-bit): 32-bit default operand size, 64-bit address size
 *   - Compatibility Mode: 32-bit code running under 64-bit OS
 *   - 64-bit Mode: Full 64-bit execution
 *
 * REX prefix (0x40-0x4F) in 64-bit mode:
 * - REX.W (bit 3): Use 64-bit operand size
 * - REX.R (bit 2): Extend ModR/M reg field
 * - REX.X (bit 1): Extend SIB index field
 * - REX.B (bit 0): Extend ModR/M r/m field or SIB base field
 */
class RuntimeCPUContext implements RuntimeCPUContextInterface
{
    // Operand/address size control
    private bool $operandSizeOverride = false;
    private bool $addressSizeOverride = false;
    private int $defaultOperandSize = 16;
    private int $defaultAddressSize = 16;

    // CPU mode
    private bool $protectedMode = false;
    private bool $longMode = false;           // IA-32e mode (64-bit)
    private bool $compatibilityMode = false;  // 32-bit code in 64-bit OS

    // REX prefix state (64-bit mode only)
    private int $rex = 0;        // Full REX byte (0x40-0x4F), or 0 if no REX
    private bool $hasRex = false;

    // Descriptor tables
    private array $gdtr = ['base' => 0, 'limit' => 0];
    private array $idtr = ['base' => 0, 'limit' => 0];
    private array $taskRegister = ['selector' => 0, 'base' => 0, 'limit' => 0];
    private array $ldtr = ['selector' => 0, 'base' => 0, 'limit' => 0];

    // Memory and paging
    private bool $a20Enabled = false;
    private bool $waitingA20OutputPort = false;
    private bool $pagingEnabled = false;

    // Privilege
    private bool $userMode = false;
    private int $cpl = 0;
    private int $iopl = 0;
    private bool $nt = false;

    // Interrupt handling
    private int $interruptDeliveryBlock = 0;

    // Segment override
    private ?RegisterType $segmentOverride = null;

    // Hardware state
    private PicState $picState;
    private ApicState $apicState;
    private KeyboardController $keyboardController;
    private Cmos $cmos;

    // Iteration context (for REP prefix, etc.)
    private IterationContextInterface $iterationContext;

    // Current instruction pointer (for iteration rewind)
    private int $currentInstructionPointer = 0;

    public function __construct()
    {
        $this->apicState = new ApicState();
        $this->picState = new PicState($this->apicState);
        $this->keyboardController = new KeyboardController($this->picState);
        $this->cmos = new Cmos();
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
        // In 64-bit mode, REX.W takes precedence
        if ($this->longMode && !$this->compatibilityMode && $this->rexW()) {
            return false;  // Using 64-bit, not 32-bit
        }

        $override = $consume ? $this->consumeOperandSizeOverride() : $this->operandSizeOverride;
        $default32 = $this->defaultOperandSize === 32;
        return $override ? !$default32 : $default32;
    }

    public function shouldUse16bit(bool $consume = true): bool
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // In 64-bit mode, 0x66 prefix toggles between 32 and 16
            $override = $consume ? $this->consumeOperandSizeOverride() : $this->operandSizeOverride;
            return $override && !$this->rexW();
        }
        return !$this->shouldUse32bit($consume);
    }

    public function shouldUse64bit(bool $consume = true): bool
    {
        if (!$this->longMode || $this->compatibilityMode) {
            return false;
        }
        // REX.W forces 64-bit operand size
        return $this->rexW();
    }

    public function operandSize(): int
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // 64-bit mode operand size:
            // - REX.W=1: 64-bit
            // - 0x66 prefix: 16-bit
            // - Default: 32-bit
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
            // Real mode defaults to 16-bit
            $this->defaultOperandSize = 16;
            $this->defaultAddressSize = 16;
            $this->longMode = false;
            $this->compatibilityMode = false;
        }
        // Note: When entering protected mode, operand/address size is determined
        // by the D/B bit in the code segment descriptor. The caller should set
        // the appropriate sizes after setting up segments, or use
        // setDefaultOperandSize/setDefaultAddressSize explicitly.
    }

    public function isProtectedMode(): bool
    {
        return $this->protectedMode;
    }

    public function setLongMode(bool $enabled): void
    {
        $this->longMode = $enabled;
        if ($enabled) {
            $this->protectedMode = true;  // Long mode requires protected mode
            // 64-bit mode defaults: 32-bit operands, 64-bit addresses
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
            return $override;  // Default is 64-bit, override gives 32-bit
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

    public function shouldUse64bitAddress(bool $consume = true): bool
    {
        if (!$this->longMode || $this->compatibilityMode) {
            return false;
        }
        // In 64-bit mode, default is 64-bit addressing
        // 0x67 prefix toggles to 32-bit
        $override = $consume ? $this->consumeAddressSizeOverride() : $this->addressSizeOverride;
        return !$override;
    }

    public function addressSize(): int
    {
        if ($this->longMode && !$this->compatibilityMode) {
            // 64-bit mode: default 64-bit, 0x67 prefix gives 32-bit
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

    // ========================================
    // REX prefix support (64-bit mode)
    // ========================================

    public function setRex(int $rex): void
    {
        $this->rex = $rex & 0x0F;  // Lower 4 bits of REX byte
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

    /**
     * REX.W: 64-bit operand size.
     */
    public function rexW(): bool
    {
        return $this->hasRex && (($this->rex >> 3) & 0x1) !== 0;
    }

    /**
     * REX.R: Extension of ModR/M reg field.
     */
    public function rexR(): bool
    {
        return $this->hasRex && (($this->rex >> 2) & 0x1) !== 0;
    }

    /**
     * REX.X: Extension of SIB index field.
     */
    public function rexX(): bool
    {
        return $this->hasRex && (($this->rex >> 1) & 0x1) !== 0;
    }

    /**
     * REX.B: Extension of ModR/M r/m field, SIB base field, or opcode reg field.
     */
    public function rexB(): bool
    {
        return $this->hasRex && ($this->rex & 0x1) !== 0;
    }

    public function clearRex(): void
    {
        $this->rex = 0;
        $this->hasRex = false;
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

    public function iteration(): IterationContextInterface
    {
        return $this->iterationContext;
    }

    public function currentInstructionPointer(): int
    {
        return $this->currentInstructionPointer;
    }

    public function setCurrentInstructionPointer(int $ip): void
    {
        $this->currentInstructionPointer = $ip;
    }
}
