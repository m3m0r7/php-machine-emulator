<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: Carry check loop (part of 64-bit comparison)
 *
 * Found at 0x16BB60 in test999 (~230k回):
 * JC/JB  target    ; 0x72 xx - Jump if carry
 * CMP    reg, reg  ; 0x3B xx - Compare
 * JA     target    ; 0x77 xx - Jump if above
 *
 * This is part of 64-bit unsigned comparison.
 */
class CarryCheckLoopPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'CarryCheckLoop';
    }

    public function priority(): int
    {
        return 88;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 4) {
            return null;
        }

        // 0x72 = JC/JB (Jump if carry/below)
        if ($bytes[0] !== 0x72) {
            return null;
        }

        // Get the JC target offset
        $jcOffset = $bytes[1];
        if ($jcOffset > 127) {
            $jcOffset = $jcOffset - 256;
        }
        $jcTarget = $ip + 2 + $jcOffset;

        return function (RuntimeInterface $runtime) use ($ip, $jcTarget): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // Check if carry flag is set
            if ($memoryAccessor->shouldCarryFlag()) {
                // JC taken - jump to target
                $memory->setOffset($jcTarget);
                return PatternedInstructionResult::success($jcTarget);
            }

            // JC not taken - fall through to next instruction
            $memory->setOffset($ip + 2);
            return PatternedInstructionResult::success($ip + 2);
        };
    }
}
