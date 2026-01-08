<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86_64\Arithmetic64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Mov64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Movsxd;
use PHPMachineEmulator\Instruction\Intel\x86_64\Pop64;
use PHPMachineEmulator\Instruction\Intel\x86_64\Push64;
use PHPMachineEmulator\Instruction\Intel\x86_64\RexPrefix;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\Traits\RuntimeAwareTrait;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * x86-64 (AMD64/Intel 64) instruction list.
 *
 * This class handles 64-bit mode specific instructions and delegates
 * to the underlying x86 class for 16/32-bit mode instructions.
 *
 * Architecture modes:
 * - Real Mode (16-bit): Delegates to x86
 * - Protected Mode (32-bit): Delegates to x86
 * - Long Mode (64-bit):
 *   - Compatibility Mode: 32-bit code, mostly delegates to x86
 *   - 64-bit Mode: Uses 64-bit specific instructions
 *
 * Key differences in 64-bit mode:
 * - 0x40-0x4F: REX prefixes (instead of INC/DEC r32)
 * - 0x63: MOVSXD (instead of ARPL)
 * - New registers: R8-R15
 * - 64-bit operand size with REX.W
 * - RIP-relative addressing
 */
class X8664 implements InstructionListInterface
{
    use RuntimeAwareTrait;
    use InstructionSupport;

    protected X86 $x86;
    protected array $instructionList64 = [];

    /** @var array<InstructionInterface> Cache for findInstruction results in 64-bit mode */
    protected array $findInstructionCache64 = [];

    public function __construct()
    {
        $this->x86 = new X86();
    }

    public function register(): RegisterInterface
    {
        return $this->x86->register();
    }

    /**
     * Get the underlying x86 instruction list (for delegation).
     */
    public function x86(): X86
    {
        return $this->x86;
    }

    /**
     * Override setRuntime to also set it on the underlying x86 instance.
     */
    public function setRuntime(RuntimeInterface $runtime): void
    {
        $this->runtime = $runtime;
        $this->x86->setRuntime($runtime);
    }

    public function findInstruction(int|array $opcodes): InstructionInterface
    {
        $key = $this->makeKeyByOpCodes($opcodes);

        // Check if we're in 64-bit mode (Long Mode and not Compatibility Mode)
        $isIn64BitMode = $this->runtime !== null
            && $this->runtime->context()->cpu()->isLongMode()
            && !$this->runtime->context()->cpu()->isCompatibilityMode();

        // Only use 64-bit specific instructions when actually in 64-bit mode
        if ($isIn64BitMode) {
            // Check cache first
            if (isset($this->findInstructionCache64[$key])) {
                return $this->findInstructionCache64[$key];
            }

            $list64 = $this->instructionList64();
            if (isset($list64[$key])) {
                return $this->findInstructionCache64[$key] = $list64[$key];
            }
        }

        // Delegate to x86 for non-64-bit mode or non-64-bit-specific opcodes
        return $this->x86->findInstruction($opcodes);
    }

    /**
     * Get the maximum opcode length supported.
     * Delegates to the underlying x86 implementation.
     */
    public function getMaxOpcodeLength(): int
    {
        return $this->x86->getMaxOpcodeLength();
    }

    /**
     * Build 64-bit mode specific instruction list.
     *
     * These instructions override or replace 32-bit instructions when in 64-bit mode.
     */
    protected function instructionList64(): array
    {
        if (!empty($this->instructionList64)) {
            return $this->instructionList64;
        }

        // 64-bit specific instruction classes
        $instructions64 = [
            RexPrefix::class,       // 0x40-0x4F: REX prefixes
            Movsxd::class,          // 0x63: MOVSXD r64, r/m32
            Push64::class,          // 0x50-0x57: PUSH r64
            Pop64::class,           // 0x58-0x5F: POP r64
            Mov64::class,           // MOV with 64-bit operands
            Arithmetic64::class,    // ADD/SUB/CMP/AND/OR/XOR with 64-bit operands
            // Syscall::class,      // 0x0F 0x05: SYSCALL (handled in TwoBytePrefix)
        ];

        foreach ($instructions64 as $className) {
            if (!class_exists($className)) {
                continue;
            }
            $instance = new $className($this);
            assert($instance instanceof InstructionInterface);

            foreach ($instance->opcodes() as $opcodes) {
                $this->instructionList64[$this->makeKeyByOpCodes($opcodes)] = $instance;
            }
        }

        return $this->instructionList64;
    }
}
