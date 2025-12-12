<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: SHRD + SHL/ROL (0x0F 0xAC -> 0xD1)
 *
 * Found at 0x16BB73, 0x16BB79 in test999 (~97万回):
 * SHRD r/m32, r32, imm8  ; 0x0F 0xAC ModR/M imm8 - Double precision shift right
 * SHL/ROL r/m32, 1       ; 0xD1 ModR/M           - Shift/Rotate left by 1
 *
 * This is 64-bit multiplication/shift emulation.
 */
class ShrdShlPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'SHRD+SHL/ROL';
    }

    public function priority(): int
    {
        return 100;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 6) {
            return null;
        }

        // 0x0F 0xAC = SHRD r/m32, r32, imm8
        if ($bytes[0] !== 0x0F || $bytes[1] !== 0xAC) {
            return null;
        }

        $modrm1 = $bytes[2];
        $mod1 = ($modrm1 >> 6) & 0x03;
        $reg1 = ($modrm1 >> 3) & 0x07;
        $rm1 = $modrm1 & 0x07;

        // We expect mod=11 (register-register)
        if ($mod1 !== 0x03) {
            return null;
        }

        $imm8 = $bytes[3];

        // Check for SHL/ROL (0xD1) at offset 4
        if ($bytes[4] !== 0xD1) {
            return null;
        }

        $modrm2 = $bytes[5];
        $mod2 = ($modrm2 >> 6) & 0x03;
        $opext = ($modrm2 >> 3) & 0x07;  // 4=SHL, 0=ROL
        $rm2 = $modrm2 & 0x07;

        if ($mod2 !== 0x03) {
            return null;
        }

        $regMap = $this->getRegisterMap();

        $shrdDst = $regMap[$rm1] ?? null;
        $shrdSrc = $regMap[$reg1] ?? null;
        $shlDst = $regMap[$rm2] ?? null;

        if ($shrdDst === null || $shrdSrc === null || $shlDst === null) {
            return null;
        }

        $isShl = ($opext === 4);
        $isRol = ($opext === 0);

        if (!$isShl && !$isRol) {
            return null;
        }

        return function (RuntimeInterface $runtime) use ($ip, $shrdDst, $shrdSrc, $shlDst, $imm8, $isShl): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // SHRD: dst = (src:dst >> imm8) & 0xFFFFFFFF
            $dst = $memoryAccessor->fetch($shrdDst)->asBytesBySize(32);
            $src = $memoryAccessor->fetch($shrdSrc)->asBytesBySize(32);

            // Combine into 64-bit and shift right
            $combined = ($src << 32) | $dst;
            $shiftCount = $imm8 & 31;
            $shrdResult = ($combined >> $shiftCount) & 0xFFFFFFFF;

            // Set CF to the last bit shifted out
            $lastBitOut = ($shiftCount > 0) ? (($dst >> ($shiftCount - 1)) & 1) : 0;
            $memoryAccessor->setCarryFlag($lastBitOut !== 0);

            // Write SHRD result
            $memoryAccessor->writeBySize($shrdDst, $shrdResult, 32);

            // SHL/ROL dst, 1
            $shlVal = $memoryAccessor->fetch($shlDst)->asBytesBySize(32);

            if ($isShl) {
                // SHL: shift left by 1
                $cfBit = ($shlVal >> 31) & 1;
                $shlResult = ($shlVal << 1) & 0xFFFFFFFF;
                $memoryAccessor->setCarryFlag($cfBit !== 0);

                // OF is set if sign bit changed
                $memoryAccessor->setOverflowFlag((($shlResult >> 31) & 1) !== $cfBit);
            } else {
                // ROL: rotate left by 1
                $cfBit = ($shlVal >> 31) & 1;
                $shlResult = (($shlVal << 1) | $cfBit) & 0xFFFFFFFF;
                $memoryAccessor->setCarryFlag($cfBit !== 0);
                $memoryAccessor->setOverflowFlag(((($shlResult >> 31) & 1) ^ $cfBit) !== 0);
            }

            $memoryAccessor->setZeroFlag($shlResult === 0);
            $memoryAccessor->setSignFlag(($shlResult & 0x80000000) !== 0);
            $memoryAccessor->writeBySize($shlDst, $shlResult, 32);

            // Next IP after both instructions (SHRD=4 bytes, SHL/ROL=2 bytes)
            $nextIp = $ip + 6;
            $memory->setOffset($nextIp);
            return PatternedInstructionResult::success($nextIp);
        };
    }
}
