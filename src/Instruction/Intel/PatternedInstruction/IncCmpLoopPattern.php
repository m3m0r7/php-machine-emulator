<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: INC + CMP loop (strlen/counter style)
 *
 * Found at 0x1001C7 in test999 (~10k回):
 * INC  ECX         ; 0x41 - Increment counter
 * CMP  reg, r/m    ; 0x3B xx - Compare
 * JAE/JNC target   ; 0x73 xx - Jump if above/equal or no carry
 * CMP  byte        ; 0x38 xx - Compare byte
 *
 * This is a strlen-style counting loop.
 */
class IncCmpLoopPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'IncCmpLoop';
    }

    public function priority(): int
    {
        return 87;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 4) {
            return null;
        }

        // 0x41 = INC ECX (in 32-bit mode)
        if ($bytes[0] !== 0x41) {
            return null;
        }

        // 0x3B = CMP r32, r/m32
        if ($bytes[1] !== 0x3B) {
            return null;
        }

        // Execute the INC ECX directly
        return function (RuntimeInterface $runtime) use ($ip): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // INC ECX
            $ecx = $memoryAccessor->fetch(RegisterType::ECX)->asBytesBySize(32);
            $result = ($ecx + 1) & 0xFFFFFFFF;

            // Set flags (INC doesn't affect CF)
            $memoryAccessor->setZeroFlag($result === 0);
            $memoryAccessor->setSignFlag(($result & 0x80000000) !== 0);
            $memoryAccessor->setOverflowFlag($ecx === 0x7FFFFFFF);
            // Parity flag for low byte
            $memoryAccessor->setParityFlag($this->calculateParity($result & 0xFF));

            // Write result
            $memoryAccessor->writeBySize(RegisterType::ECX, $result, 32);

            // Move to next instruction
            $memory->setOffset($ip + 1);
            return PatternedInstructionResult::success($ip + 1);
        };
    }
}
