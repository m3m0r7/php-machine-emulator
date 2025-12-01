<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\OperationNotFoundException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86_64\RexPrefix;
use PHPMachineEmulator\Instruction\Intel\x86_64\Movsxd;
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
class x86_64 implements InstructionListInterface
{
    use RuntimeAwareTrait;

    protected x86 $x86;
    protected array $instructionList64 = [];

    public function __construct()
    {
        $this->x86 = new x86();
    }

    public function register(): RegisterInterface
    {
        return $this->x86->register();
    }

    /**
     * Get the underlying x86 instruction list (for delegation).
     */
    public function x86(): x86
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

    public function getInstructionByOperationCode(int $opcode): InstructionInterface
    {
        // Always check 64-bit specific opcodes first.
        // The instruction's process() method will check actual CPU mode
        // and behave accordingly (e.g., REX prefix vs INC/DEC).
        $list64 = $this->instructionList64();
        if (isset($list64[$opcode])) {
            return $list64[$opcode];
        }

        // Delegate to x86 for non-64-bit-specific opcodes
        return $this->x86->getInstructionByOperationCode($opcode);
    }

    /**
     * Get the standard x86 instruction list (for 16/32-bit modes).
     */
    public function instructionList(): array
    {
        return $this->x86->instructionList();
    }

    /**
     * Build 64-bit mode specific instruction list.
     *
     * These instructions override or replace 32-bit instructions when in 64-bit mode.
     */
    public function instructionList64(): array
    {
        if (!empty($this->instructionList64)) {
            return $this->instructionList64;
        }

        // 64-bit specific instruction classes
        $instructions64 = [
            RexPrefix::class,       // 0x40-0x4F: REX prefixes
            Movsxd::class,          // 0x63: MOVSXD r64, r/m32
            // Syscall::class,      // 0x0F 0x05: SYSCALL (handled in TwoBytePrefix)
            // Sysret::class,       // 0x0F 0x07: SYSRET (handled in TwoBytePrefix)
        ];

        foreach ($instructions64 as $className) {
            if (!class_exists($className)) {
                continue;
            }
            $instance = new $className($this);
            assert($instance instanceof InstructionInterface);

            foreach ($instance->opcodes() as $opcode) {
                $this->instructionList64[$opcode] = $instance;
            }
        }

        return $this->instructionList64;
    }
}
