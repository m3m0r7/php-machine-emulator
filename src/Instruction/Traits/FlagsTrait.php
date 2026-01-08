<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * Trait for CPU flags operations.
 * Provides methods for packing and unpacking EFLAGS/RFLAGS register.
 * Used by both x86 and x86_64 instructions.
 */
trait FlagsTrait
{
    /**
     * Pack CPU flags into a single value.
     *
     * EFLAGS bit layout:
     * - Bit 0: CF (Carry Flag)
     * - Bit 1: Reserved (always 1)
     * - Bit 2: PF (Parity Flag)
     * - Bit 4: AF (Auxiliary Flag)
     * - Bit 6: ZF (Zero Flag)
     * - Bit 7: SF (Sign Flag)
     * - Bit 8: TF (Trap Flag)
     * - Bit 9: IF (Interrupt Flag)
     * - Bit 10: DF (Direction Flag)
     * - Bit 11: OF (Overflow Flag)
     * - Bits 12-13: IOPL (I/O Privilege Level)
     * - Bit 14: NT (Nested Task)
     * - Bit 16: RF (Resume Flag)
     * - Bit 17: VM (Virtual 8086 Mode)
     * - Bit 18: AC (Alignment Check)
     * - Bit 19: VIF (Virtual Interrupt Flag)
     * - Bit 20: VIP (Virtual Interrupt Pending)
     * - Bit 21: ID (ID Flag)
     */
    protected function packFlags(RuntimeInterface $runtime): int
    {
        $ma = $runtime->memoryAccessor();
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            0x2 | // reserved bit 1 set
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0);
        $flags |= ($runtime->context()->cpu()->iopl() & 0x3) << 12;
        if ($runtime->context()->cpu()->nt()) {
            $flags |= (1 << 14);
        }
        if ($runtime->context()->cpu()->idFlag()) {
            $flags |= (1 << 21);
        }
        return $flags & 0xFFFFFFFF;
    }

    /**
     * Pack CPU flags into RFLAGS (64-bit).
     */
    protected function packFlags64(RuntimeInterface $runtime): int
    {
        // For 64-bit, upper 32 bits are reserved and should be 0
        return $this->packFlags($runtime) & 0x00000000FFFFFFFF;
    }

    /**
     * Apply packed flags to CPU state.
     */
    protected function applyFlags(RuntimeInterface $runtime, int $flags, int $size = 32): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->setCarryFlag(($flags & 0x1) !== 0);
        $ma->setParityFlag(($flags & (1 << 2)) !== 0);
        $ma->setZeroFlag(($flags & (1 << 6)) !== 0);
        $ma->setSignFlag(($flags & (1 << 7)) !== 0);
        $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
        $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);
        $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
        $runtime->context()->cpu()->setIopl(($flags >> 12) & 0x3);
        $runtime->context()->cpu()->setNt(($flags & (1 << 14)) !== 0);
        if ($size >= 32) {
            $runtime->context()->cpu()->setIdFlag(($flags & (1 << 21)) !== 0);
        }
    }

    /**
     * Update flags after arithmetic operation.
     *
     * @param int|UInt64 $result The result of the operation
     * @param int $size Operand size in bits (8, 16, 32, or 64)
     * @param bool $updateCf Whether to update carry flag
     * @param bool $updateOf Whether to update overflow flag
     */
    protected function updateArithmeticFlags(
        RuntimeInterface $runtime,
        int|UInt64 $result,
        int $size,
        bool $updateCf = true,
        bool $updateOf = true
    ): void {
        $ma = $runtime->memoryAccessor();

        if ($size === 64) {
            $this->updateArithmeticFlags64($runtime, $result, $updateCf, $updateOf);
            return;
        }

        $mask = match ($size) {
            8 => 0xFF,
            16 => 0xFFFF,
            default => 0xFFFFFFFF,
        };
        $signBit = match ($size) {
            8 => 0x80,
            16 => 0x8000,
            default => 0x80000000,
        };

        $resultInt = $result instanceof UInt64 ? $result->low32() : $result;
        $maskedResult = $resultInt & $mask;

        // Zero flag
        $ma->setZeroFlag($maskedResult === 0);

        // Sign flag
        $ma->setSignFlag(($maskedResult & $signBit) !== 0);

        // Parity flag (count of set bits in low byte)
        $lowByte = $maskedResult & 0xFF;
        $bitCount = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($lowByte & (1 << $i)) !== 0) {
                $bitCount++;
            }
        }
        $ma->setParityFlag(($bitCount % 2) === 0);
    }

    /**
     * Update flags after 64-bit arithmetic operation.
     */
    protected function updateArithmeticFlags64(
        RuntimeInterface $runtime,
        int|UInt64 $result,
        bool $updateCf = true,
        bool $updateOf = true
    ): void {
        $ma = $runtime->memoryAccessor();

        $uint64 = $result instanceof UInt64 ? $result : UInt64::of($result);

        // Zero flag
        $ma->setZeroFlag($uint64->isZero());

        // Sign flag (bit 63)
        $ma->setSignFlag($uint64->isNegativeSigned());

        // Parity flag (count of set bits in low byte)
        $lowByte = $uint64->low32() & 0xFF;
        $bitCount = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($lowByte & (1 << $i)) !== 0) {
                $bitCount++;
            }
        }
        $ma->setParityFlag(($bitCount % 2) === 0);
    }

    /**
     * Update flags after logical operation.
     * Logical operations clear CF and OF.
     */
    protected function updateLogicalFlags(RuntimeInterface $runtime, int|UInt64 $result, int $size): void
    {
        $ma = $runtime->memoryAccessor();

        // Clear CF and OF for logical operations
        $ma->setCarryFlag(false);
        $ma->setOverflowFlag(false);

        $this->updateArithmeticFlags($runtime, $result, $size, false, false);
    }
}
