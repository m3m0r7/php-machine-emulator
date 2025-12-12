<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: ADD + ADC (0x01 -> 0x11)
 *
 * Found at 0x16BB14-0x16BB24 in test999 (~96万回):
 * ADD r/m32, r32    ; 0x01 ModR/M - Add (sets CF)
 * ADC r/m32, r32    ; 0x11 ModR/M - Add with carry
 *
 * This is 64-bit addition emulation (low + high with carry propagation).
 */
class AddAdcPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'ADD+ADC';
    }

    public function priority(): int
    {
        return 99;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 4) {
            return null;
        }

        // 0x01 = ADD r/m32, r32
        if ($bytes[0] !== 0x01) {
            return null;
        }

        $modrm1 = $bytes[1];
        $mod1 = ($modrm1 >> 6) & 0x03;
        $reg1 = ($modrm1 >> 3) & 0x07;
        $rm1 = $modrm1 & 0x07;

        // We expect mod=11 (register-register)
        if ($mod1 !== 0x03) {
            return null;
        }

        // Check for ADC (0x11) at offset 2
        if ($bytes[2] !== 0x11) {
            return null;
        }

        $modrm2 = $bytes[3];
        $mod2 = ($modrm2 >> 6) & 0x03;
        $reg2 = ($modrm2 >> 3) & 0x07;
        $rm2 = $modrm2 & 0x07;

        if ($mod2 !== 0x03) {
            return null;
        }

        $regMap = $this->getRegisterMap();

        $addDst = $regMap[$rm1] ?? null;
        $addSrc = $regMap[$reg1] ?? null;
        $adcDst = $regMap[$rm2] ?? null;
        $adcSrc = $regMap[$reg2] ?? null;

        if ($addDst === null || $addSrc === null || $adcDst === null || $adcSrc === null) {
            return null;
        }

        return function (RuntimeInterface $runtime) use ($ip, $addDst, $addSrc, $adcDst, $adcSrc): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // ADD dst, src
            $dst1 = $memoryAccessor->fetch($addDst)->asBytesBySize(32);
            $src1 = $memoryAccessor->fetch($addSrc)->asBytesBySize(32);
            $sum1 = $dst1 + $src1;
            $result1 = $sum1 & 0xFFFFFFFF;
            $cf1 = $sum1 > 0xFFFFFFFF;

            // Set flags for ADD
            $memoryAccessor->setCarryFlag($cf1);
            $memoryAccessor->setZeroFlag($result1 === 0);
            $memoryAccessor->setSignFlag(($result1 & 0x80000000) !== 0);

            // Overflow: sign of result differs when adding same-sign operands
            $dst1Sign = ($dst1 & 0x80000000) !== 0;
            $src1Sign = ($src1 & 0x80000000) !== 0;
            $res1Sign = ($result1 & 0x80000000) !== 0;
            $memoryAccessor->setOverflowFlag(($dst1Sign === $src1Sign) && ($res1Sign !== $dst1Sign));

            $memoryAccessor->writeBySize($addDst, $result1, 32);

            // ADC dst, src (uses CF from ADD)
            $dst2 = $memoryAccessor->fetch($adcDst)->asBytesBySize(32);
            $src2 = $memoryAccessor->fetch($adcSrc)->asBytesBySize(32);
            $carry = $cf1 ? 1 : 0;
            $sum2 = $dst2 + $src2 + $carry;
            $result2 = $sum2 & 0xFFFFFFFF;
            $cf2 = $sum2 > 0xFFFFFFFF;

            // Set flags for ADC
            $memoryAccessor->setCarryFlag($cf2);
            $memoryAccessor->setZeroFlag($result2 === 0);
            $memoryAccessor->setSignFlag(($result2 & 0x80000000) !== 0);

            $dst2Sign = ($dst2 & 0x80000000) !== 0;
            $src2Sign = ($src2 & 0x80000000) !== 0;
            $res2Sign = ($result2 & 0x80000000) !== 0;
            $memoryAccessor->setOverflowFlag(($dst2Sign === $src2Sign) && ($res2Sign !== $dst2Sign));

            $memoryAccessor->writeBySize($adcDst, $result2, 32);

            // Next IP after both instructions (ADD=2 bytes, ADC=2 bytes)
            $nextIp = $ip + 4;
            $memory->setOffset($nextIp);
            return PatternedInstructionResult::success($nextIp);
        };
    }
}
