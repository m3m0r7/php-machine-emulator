<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: 64-bit right shift loop (CMP + JA based)
 *
 * Found at 0x16BB5B in test999 (~50万回):
 * CMP  ESI, EDI    ; 0x3B 0xF7 - Compare ESI with EDI
 * JA   target      ; 0x77 xx   - Jump if above (CF=0 and ZF=0)
 *
 * This is part of 64-bit division emulation.
 * We execute CMP and JA directly in PHP.
 */
class ShiftRightLoopPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return '64BitShiftRightLoop';
    }

    public function priority(): int
    {
        return 89;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 4) {
            return null;
        }

        // 0x3B = CMP r32, r/m32
        if ($bytes[0] !== 0x3B) {
            return null;
        }

        $modrm = $bytes[1];
        $mod = ($modrm >> 6) & 0x03;
        $reg = ($modrm >> 3) & 0x07;
        $rm = $modrm & 0x07;

        // We expect mod=11 (register-register comparison)
        if ($mod !== 0x03) {
            return null;
        }

        // Check for JA (0x77) at offset 3
        if ($bytes[2] !== 0x77) {
            return null;
        }

        // Get JA target offset
        $jaOffset = $bytes[3];
        if ($jaOffset > 127) {
            $jaOffset = $jaOffset - 256;
        }
        $jaTarget = $ip + 4 + $jaOffset;

        $regMap = $this->getRegisterMap();

        $srcReg = $regMap[$rm] ?? null;
        $dstReg = $regMap[$reg] ?? null;

        if ($srcReg === null || $dstReg === null) {
            return null;
        }

        return function (RuntimeInterface $runtime) use ($ip, $jaTarget, $srcReg, $dstReg): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // CMP dstReg, srcReg (dst - src, set flags, discard result)
            $dst = $memoryAccessor->fetch($dstReg)->asBytesBySize(32);
            $src = $memoryAccessor->fetch($srcReg)->asBytesBySize(32);

            // Perform subtraction for flag calculation
            $result = ($dst - $src) & 0xFFFFFFFF;

            // Set flags
            $memoryAccessor->setCarryFlag($dst < $src);  // Unsigned borrow
            $memoryAccessor->setZeroFlag($result === 0);
            $memoryAccessor->setSignFlag(($result & 0x80000000) !== 0);

            // Overflow: signed overflow when sign of result differs from expected
            $dstSign = ($dst & 0x80000000) !== 0;
            $srcSign = ($src & 0x80000000) !== 0;
            $resSign = ($result & 0x80000000) !== 0;
            $memoryAccessor->setOverflowFlag(($dstSign !== $srcSign) && ($resSign === $srcSign));

            // JA: Jump if Above (CF=0 AND ZF=0)
            if (!$memoryAccessor->shouldCarryFlag() && !$memoryAccessor->shouldZeroFlag()) {
                // JA taken
                $memory->setOffset($jaTarget);
                return PatternedInstructionResult::success($jaTarget);
            }

            // JA not taken - fall through
            $memory->setOffset($ip + 4);
            return PatternedInstructionResult::success($ip + 4);
        };
    }
}
