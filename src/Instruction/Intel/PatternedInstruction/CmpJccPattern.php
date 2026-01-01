<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: CMP + Jcc (0x39/0x3B + 0x7x/0x0F 0x8x)
 *
 * Found at 0x16BB5B in test999 (~72万回 for CMP+JA):
 * CMP r32, r/m32   ; 0x3B ModR/M - Compare (sets flags)
 * Jcc target       ; 0x7x rel8 or 0x0F 0x8x rel32 - Conditional jump
 *
 * Covers: JA(77), JAE(73), JB(72), JBE(76), JE/JZ(74), JNE/JNZ(75),
 *         JG(7F), JGE(7D), JL(7C), JLE(7E), JS(78), JNS(79)
 */
class CmpJccPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'CMP+Jcc';
    }

    public function priority(): int
    {
        return 98;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 4) {
            return null;
        }

        // CMP opcodes: 0x39 (r/m16/32,r16/32), 0x3B (r16/32,r/m16/32)
        $cmpOpcode = $bytes[0];
        if ($cmpOpcode !== 0x39 && $cmpOpcode !== 0x3B) {
            return null;
        }

        $modrm = $bytes[1];
        $mod = ($modrm >> 6) & 0x03;
        $reg = ($modrm >> 3) & 0x07;
        $rm = $modrm & 0x07;

        // For now, only support mod=11 (register-register)
        if ($mod !== 0x03) {
            return null;
        }

        $regIsDst = ($cmpOpcode === 0x3B);

        // Check for short Jcc (0x70-0x7F) at offset 2
        $jccOpcode = $bytes[2];
        $isShortJcc = ($jccOpcode >= 0x70 && $jccOpcode <= 0x7F);
        $isLongJcc = ($jccOpcode === 0x0F && isset($bytes[3]) && $bytes[3] >= 0x80 && $bytes[3] <= 0x8F);

        if (!$isShortJcc && !$isLongJcc) {
            return null;
        }

        // Decode Jcc condition
        $condition = $isShortJcc ? ($jccOpcode & 0x0F) : ($bytes[3] & 0x0F);

        $rel8 = 0;
        $rel16 = 0;
        $rel32 = 0;

        // Decode relative offsets (actual size depends on operand size at runtime for 0F 8x)
        if ($isShortJcc) {
            $rel = $bytes[3];
            if ($rel > 127) {
                $rel = $rel - 256;
            }
            $rel8 = $rel;
        } else {
            if (count($bytes) < 8) {
                return null;
            }

            $rel16 = ($bytes[4] | ($bytes[5] << 8)) & 0xFFFF;
            if ($rel16 & 0x8000) {
                $rel16 -= 0x10000;
            }

            $rel32 = (
                $bytes[4]
                | ($bytes[5] << 8)
                | ($bytes[6] << 16)
                | ($bytes[7] << 24)
            ) & 0xFFFFFFFF;
            if ($rel32 & 0x80000000) {
                $rel32 -= 0x100000000;
            }
        }

        $regMap = $this->getRegisterMap();

        if ($regIsDst) {
            $dstReg = $regMap[$reg] ?? null;
            $srcReg = $regMap[$rm] ?? null;
        } else {
            $dstReg = $regMap[$rm] ?? null;
            $srcReg = $regMap[$reg] ?? null;
        }

        if ($dstReg === null || $srcReg === null) {
            return null;
        }

        return function (RuntimeInterface $runtime) use ($ip, $dstReg, $srcReg, $condition, $isShortJcc, $isLongJcc, $rel8, $rel16, $rel32): PatternedInstructionResult {
            $memoryAccessor = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // CMP: dst - src, set flags, discard result
            $opSize = $runtime->context()->cpu()->operandSize();
            if ($opSize !== 16 && $opSize !== 32) {
                return PatternedInstructionResult::skip($ip);
            }

            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signMask = $opSize === 32 ? 0x80000000 : 0x8000;

            $dst = $memoryAccessor->fetch($dstReg)->asBytesBySize($opSize) & $mask;
            $src = $memoryAccessor->fetch($srcReg)->asBytesBySize($opSize) & $mask;

            $result = ($dst - $src) & $mask;

            // Set flags
            $cf = $dst < $src;  // Unsigned borrow
            $zf = $result === 0;
            $sf = ($result & $signMask) !== 0;
            $pf = $this->calculateParity($result & 0xFF);

            // Overflow detection for signed subtraction
            $dstSign = ($dst & $signMask) !== 0;
            $srcSign = ($src & $signMask) !== 0;
            $resSign = ($result & $signMask) !== 0;
            $of = ($dstSign !== $srcSign) && ($resSign === $srcSign);

            $memoryAccessor->setCarryFlag($cf);
            $memoryAccessor->setZeroFlag($zf);
            $memoryAccessor->setSignFlag($sf);
            $memoryAccessor->setOverflowFlag($of);
            $memoryAccessor->setParityFlag($pf);

            // Calculate target/next IP (Jcc size depends on operand size for 0F 8x)
            if ($isShortJcc) {
                $nextIp = $ip + 4; // CMP(2) + Jcc rel8(2)
                $jccTarget = $nextIp + $rel8;
            } elseif ($isLongJcc) {
                if ($opSize === 16) {
                    $nextIp = $ip + 6; // CMP(2) + 0F 8x rel16(4)
                    $jccTarget = $nextIp + $rel16;
                } else {
                    $nextIp = $ip + 8; // CMP(2) + 0F 8x rel32(6)
                    $jccTarget = $nextIp + $rel32;
                }
            } else {
                return PatternedInstructionResult::skip($ip);
            }

            // Evaluate Jcc condition
            $taken = match ($condition) {
                0x0 => $of,                    // JO: OF=1
                0x1 => !$of,                   // JNO: OF=0
                0x2 => $cf,                    // JB/JC/JNAE: CF=1
                0x3 => !$cf,                   // JAE/JNB/JNC: CF=0
                0x4 => $zf,                    // JE/JZ: ZF=1
                0x5 => !$zf,                   // JNE/JNZ: ZF=0
                0x6 => $cf || $zf,             // JBE/JNA: CF=1 OR ZF=1
                0x7 => !$cf && !$zf,           // JA/JNBE: CF=0 AND ZF=0
                0x8 => $sf,                    // JS: SF=1
                0x9 => !$sf,                   // JNS: SF=0
                0xA => $pf,                    // JP/JPE: PF=1
                0xB => !$pf,                   // JNP/JPO: PF=0
                0xC => $sf !== $of,            // JL/JNGE: SF≠OF
                0xD => $sf === $of,            // JGE/JNL: SF=OF
                0xE => $zf || ($sf !== $of),   // JLE/JNG: ZF=1 OR SF≠OF
                0xF => !$zf && ($sf === $of),  // JG/JNLE: ZF=0 AND SF=OF
                default => false,
            };

            if ($taken) {
                $memory->setOffset($jccTarget);
                return PatternedInstructionResult::success($jccTarget);
            }

            $memory->setOffset($nextIp);
            return PatternedInstructionResult::success($nextIp);
        };
    }
}
