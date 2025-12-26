<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: 64-bit left shift loop (ADD/ADC based)
 *
 * Found at 0x16BB34 in test999 (~50万回):
 * TEST ESI, ESI    ; 0x85 0xF6 - Check sign bit of shift count
 * JNS  target      ; 0x79 xx   - Jump if not negative
 * ... (loop body with ADD/ADC for 64-bit shift left by 1)
 * JMP  back        ; 0xEB xx   - Loop back
 *
 * This is 64-bit multiplication emulation using repeated shift-left.
 */
class ShiftLeftLoopPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return '64BitShiftLeftLoop';
    }

    public function priority(): int
    {
        return 90;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 4) {
            return null;
        }

        // 0x85 0xF6 = TEST ESI, ESI
        if ($bytes[0] !== 0x85 || $bytes[1] !== 0xF6) {
            return null;
        }

        // 0x79 = JNS (jump if not sign)
        if ($bytes[2] !== 0x79) {
            return null;
        }

        // This is the 64-bit shift left loop pattern
        // When ESI becomes negative (sign bit set), the loop exits

        // Pre-calculate the JNS target offset
        $jnsOffset = $bytes[3];
        if ($jnsOffset > 127) {
            $jnsOffset = $jnsOffset - 256;
        }
        $jnsTarget = $ip + 4 + $jnsOffset;

        return function (RuntimeInterface $runtime) use ($ip, $jnsTarget): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // Read ESI to check sign
            $esi = $memoryAccessor->fetch(RegisterType::ESI)->asBytesBySize(32);

            // TEST ESI, ESI sets flags based on AND result
            $result = $esi & $esi;
            $memoryAccessor->setZeroFlag($result === 0);
            $memoryAccessor->setSignFlag(($result & 0x80000000) !== 0);
            $memoryAccessor->setCarryFlag(false);
            $memoryAccessor->setOverflowFlag(false);

            // Check if we should take the JNS jump (sign flag = 0)
            if (!$memoryAccessor->shouldSignFlag()) {
                // JNS taken - jump to loop body
                $memory->setOffset($jnsTarget);
                return PatternedInstructionResult::success($jnsTarget);
            }

            // JNS not taken - fall through (loop exit)
            $memory->setOffset($ip + 4);
            return PatternedInstructionResult::success($ip + 4);
        };
    }
}
