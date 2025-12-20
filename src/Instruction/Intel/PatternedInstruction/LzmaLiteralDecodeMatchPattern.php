<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: GRUB/LZMA "literal with match" decode inner loop (32-bit).
 *
 * This loop decodes a literal byte when a match byte is available, using a
 * match-conditioned probability tree until the first bit differs, then falls
 * back to normal literal decoding for the remaining bits.
 *
 * Hot code (GRUB core LZMA decoder):
 *   8B44..8B71  match-conditioned loop
 *   8B73..8B8A  normal bit-tree decode loop (handled inline here too)
 * Exit is at 0x8B8C (after edx >= 0x100).
 */
final class LzmaLiteralDecodeMatchPattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'LZMA literal decode (match)';
    }

    public function priority(): int
    {
        return 220;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        // Needs the full match-loop bytes plus the next cmp/jnc of the normal loop.
        if (count($bytes) < 55) {
            return null;
        }

        // Validate fixed bytes around the dynamic rel8/rel32 immediates.
        if (
            // cmp edx, 0x100 ; jnc rel8
            ($bytes[0] ?? null) !== 0x81 || ($bytes[1] ?? null) !== 0xFA
            || ($bytes[2] ?? null) !== 0x00 || ($bytes[3] ?? null) !== 0x01
            || ($bytes[4] ?? null) !== 0x00 || ($bytes[5] ?? null) !== 0x00
            || ($bytes[6] ?? null) !== 0x73
            // xor eax,eax ; shl byte [esp],1 ; adc eax,eax ; push eax ; push edx
            || ($bytes[8] ?? null) !== 0x31 || ($bytes[9] ?? null) !== 0xC0
            || ($bytes[10] ?? null) !== 0xD0 || ($bytes[11] ?? null) !== 0x24 || ($bytes[12] ?? null) !== 0x24
            || ($bytes[13] ?? null) !== 0x11 || ($bytes[14] ?? null) !== 0xC0
            || ($bytes[15] ?? null) !== 0x50
            || ($bytes[16] ?? null) !== 0x52
            // shl eax,8 ; lea eax,[edx+eax+0x100] ; add eax,[esp+0xc] ; call rel32
            || ($bytes[17] ?? null) !== 0xC1 || ($bytes[18] ?? null) !== 0xE0 || ($bytes[19] ?? null) !== 0x08
            || ($bytes[20] ?? null) !== 0x8D || ($bytes[21] ?? null) !== 0x84 || ($bytes[22] ?? null) !== 0x02
            || ($bytes[23] ?? null) !== 0x00 || ($bytes[24] ?? null) !== 0x01 || ($bytes[25] ?? null) !== 0x00 || ($bytes[26] ?? null) !== 0x00
            || ($bytes[27] ?? null) !== 0x03 || ($bytes[28] ?? null) !== 0x44 || ($bytes[29] ?? null) !== 0x24 || ($bytes[30] ?? null) !== 0x0C
            || ($bytes[31] ?? null) !== 0xE8
            // setc al ; pop edx ; adc edx,edx ; pop ecx ; cmp al,cl ; jz rel8
            || ($bytes[36] ?? null) !== 0x0F || ($bytes[37] ?? null) !== 0x92 || ($bytes[38] ?? null) !== 0xC0
            || ($bytes[39] ?? null) !== 0x5A
            || ($bytes[40] ?? null) !== 0x11 || ($bytes[41] ?? null) !== 0xD2
            || ($bytes[42] ?? null) !== 0x59
            || ($bytes[43] ?? null) !== 0x38 || ($bytes[44] ?? null) !== 0xC8
            || ($bytes[45] ?? null) !== 0x74
        ) {
            return null;
        }

        // Validate jnc target == exit (0x8B8C relative to this IP).
        $jncRel = self::signExtend8($bytes[7]);
        $exitIp = ($ip + 8 + $jncRel) & 0xFFFFFFFF;
        if ($exitIp !== (($ip + 0x48) & 0xFFFFFFFF)) {
            return null;
        }

        // Validate call target is 0x89ED.
        $rel = ($bytes[32] & 0xFF)
            | (($bytes[33] & 0xFF) << 8)
            | (($bytes[34] & 0xFF) << 16)
            | (($bytes[35] & 0xFF) << 24);
        if (($rel & 0x80000000) !== 0) {
            $rel -= 0x1_0000_0000;
        }
        $callTarget = ($ip + 36 + $rel) & 0xFFFFFFFF;
        if ($callTarget !== 0x000089ED) {
            return null;
        }

        // Validate jz loops back to the start of the match-loop.
        $jzRel = self::signExtend8($bytes[46]);
        $loopTarget = ($ip + 47 + $jzRel) & 0xFFFFFFFF;
        if ($loopTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        // Validate that the next instruction begins the normal decode loop (cmp edx, 0x100; jnc ...).
        if (
            ($bytes[47] ?? null) !== 0x81 || ($bytes[48] ?? null) !== 0xFA
            || ($bytes[49] ?? null) !== 0x00 || ($bytes[50] ?? null) !== 0x01
            || ($bytes[51] ?? null) !== 0x00 || ($bytes[52] ?? null) !== 0x00
            || ($bytes[53] ?? null) !== 0x73
        ) {
            return null;
        }

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, $exitIp, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();

            if ($cpu->isLongMode() || $cpu->addressSize() !== 32 || $cpu->operandSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($cpu->isPagingEnabled()) {
                return PatternedInstructionResult::skip($ip);
            }
            if (!$cpu->isA20Enabled()) {
                return PatternedInstructionResult::skip($ip);
            }

            $ma = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;
            if ($edx >= 0x100) {
                $this->setCmp32Flags($ma, $edx, 0x100);
                $runtime->context()->cpu()->clearTransientOverrides();
                $memory->setOffset($exitIp);
                return PatternedInstructionResult::success($exitIp);
            }

            $ssBase = self::resolveSegmentBase($runtime, RegisterType::SS);
            $esp = $ma->fetch(RegisterType::ESP)->asBytesBySize(32) & 0xFFFFFFFF;
            $stackLinear = ($ssBase + $esp) & 0xFFFFFFFF;

            // At 0x8B44 entry:
            //   [esp+0] = matchByte (low byte of dword), shifted each match-iteration
            //   [esp+4] = literal context base index (added to EAX before call)
            $baseIndex = $ma->readPhysical32(($stackLinear + 4) & 0xFFFFFFFF) & 0xFFFFFFFF;

            // Preserve current EAX/ECX for the rare "edx>=0x100 at entry" path above.
            $eaxReg = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $ecxReg = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;

            $linearMask = 0xFFFFFFFF;

            // Match-conditioned loop: continue while bits match the matchByte's MSB stream.
            while ($edx < 0x100) {
                $matchDword = $ma->readPhysical32($stackLinear) & 0xFFFFFFFF;
                $matchByte = $matchDword & 0xFF;
                $matchBit = ($matchByte >> 7) & 1;

                // shl byte [esp],1 (updates only the low byte in memory)
                $shifted = (($matchByte << 1) & 0xFF);
                $matchDword = ($matchDword & 0xFFFFFF00) | $shifted;
                $ma->writePhysical32($stackLinear, $matchDword);

                // index = baseIndex + 0x100 + edx + (matchBit<<8)
                $index = ($baseIndex + 0x100 + $edx + (($matchBit & 1) << 8)) & 0xFFFFFFFF;
                [$bit, $retEax] = $this->lzmaRangeDecodeBit($runtime, $index, $linearMask);

                // setc al (AL=bit, upper bytes from EAX return)
                $eaxReg = (($retEax & 0xFFFFFF00) | ($bit & 1)) & 0xFFFFFFFF;
                // pop ecx restores the pushed matchBit
                $ecxReg = $matchBit & 0xFFFFFFFF;

                // adc edx, edx (with CF=bit)
                $edx = (($edx << 1) | ($bit & 1)) & 0xFFFFFFFF;

                if (($bit & 1) !== ($matchBit & 1)) {
                    break;
                }
            }

            // If we completed all 8 bits in match mode, we jump to exit.
            if ($edx < 0x100) {
                // Normal loop: decode remaining bits with index = baseIndex + edx.
                while ($edx < 0x100) {
                    $index = ($baseIndex + $edx) & 0xFFFFFFFF;
                    [$bit, $retEax, $probAddr] = $this->lzmaRangeDecodeBit($runtime, $index, $linearMask);
                    $eaxReg = $retEax;
                    $ecxReg = $probAddr;
                    $edx = (($edx << 1) | ($bit & 1)) & 0xFFFFFFFF;
                }
            }

            $ma->writeBySize(RegisterType::EDX, $edx, 32);
            $ma->writeBySize(RegisterType::EAX, $eaxReg, 32);
            $ma->writeBySize(RegisterType::ECX, $ecxReg, 32);

            $this->setCmp32Flags($ma, $edx, 0x100);

            if (!$logged) {
                $logged = true;
                $env = getenv('PHPME_TRACE_HOT_PATTERNS');
                if ($env !== false && trim($env) !== '' && trim($env) !== '0') {
                    $runtime->option()->logger()->warning(sprintf(
                        'HOT PATTERN exec: %s ip=0x%08X',
                        $patternName,
                        $ip & 0xFFFFFFFF,
                    ));
                }
            }

            $runtime->context()->cpu()->clearTransientOverrides();
            $memory->setOffset($exitIp);
            return PatternedInstructionResult::success($exitIp);
        };
    }

    private static function signExtend8(int $imm): int
    {
        $imm &= 0xFF;
        return ($imm & 0x80) !== 0 ? ($imm - 0x100) : $imm;
    }

    private static function resolveSegmentBase(RuntimeInterface $runtime, RegisterType $segment): int
    {
        $cpu = $runtime->context()->cpu();
        $cached = $cpu->getCachedSegmentDescriptor($segment);
        if ($cached !== null) {
            $present = (bool) ($cached['present'] ?? true);
            if (!$present) {
                return 0;
            }
            return (int) ($cached['base'] ?? 0);
        }

        if (!$cpu->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
            return (($selector << 4) & 0xFFFFF);
        }

        // Match SegmentTrait behavior for missing descriptors: assume base 0.
        return 0;
    }

    private function setCmp32Flags($ma, int $dest, int $src): void
    {
        $result = ($dest - $src) & 0xFFFFFFFF;

        $ma->setCarryFlag(($dest & 0xFFFFFFFF) < ($src & 0xFFFFFFFF));
        $ma->setZeroFlag($result === 0);
        $ma->setSignFlag(($result & 0x80000000) !== 0);
        $ma->setParityFlag($this->calculateParity($result & 0xFF));

        $ma->setAuxiliaryCarryFlag((($dest ^ $src ^ $result) & 0x10) !== 0);

        $signA = ($dest >> 31) & 1;
        $signB = ($src >> 31) & 1;
        $signR = ($result >> 31) & 1;
        $ma->setOverflowFlag(($signA !== $signB) && ($signA !== $signR));
    }

    /**
     * Inline implementation of the range-decode-bit routine (0x89ED).
     *
     * @return array{int,int,int} [bit(0|1), eaxReturn, probAddr]
     */
    private function lzmaRangeDecodeBit(RuntimeInterface $runtime, int $index, int $linearMask): array
    {
        $ma = $runtime->memoryAccessor();

        $ebx = $ma->fetch(RegisterType::EBX)->asBytesBySize(32) & 0xFFFFFFFF;
        $ebp = $ma->fetch(RegisterType::EBP)->asBytesBySize(32) & 0xFFFFFFFF;
        $esi = $ma->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;

        $rangeAddr = ($ebp - 0x0C) & 0xFFFFFFFF;
        $codeAddr = ($ebp - 0x10) & 0xFFFFFFFF;

        $range = $ma->readPhysical32($rangeAddr) & 0xFFFFFFFF;
        $code = $ma->readPhysical32($codeAddr) & 0xFFFFFFFF;

        $probAddr = ($ebx + (($index & 0xFFFFFFFF) * 4)) & 0xFFFFFFFF;
        $prob = $ma->readPhysical32($probAddr) & 0xFFFFFFFF;

        $rangeShift = ($range >> 11) & 0xFFFFFFFF;
        $bound = (int) (($rangeShift * $prob) & 0xFFFFFFFF);

        $bit = 0;
        if (($code & 0xFFFFFFFF) < ($bound & 0xFFFFFFFF)) {
            // bit=0
            $range = $bound & 0xFFFFFFFF;
            $prob = ($prob + ((0x800 - $prob) >> 5)) & 0xFFFFFFFF;
            $bit = 0;
        } else {
            // bit=1
            $range = ($range - $bound) & 0xFFFFFFFF;
            $code = ($code - $bound) & 0xFFFFFFFF;
            $prob = ($prob - ($prob >> 5)) & 0xFFFFFFFF;
            $bit = 1;
        }

        $retEax = $bound & 0xFFFFFFFF;
        if (($range & 0xFFFFFFFF) < 0x01000000) {
            $range = ($range << 8) & 0xFFFFFFFF;

            $byte = $ma->readPhysical8($esi) & 0xFF;
            $esi = ($esi + ($ma->shouldDirectionFlag() ? -1 : 1)) & 0xFFFFFFFF;

            $code = (($code << 8) & 0xFFFFFFFF) | $byte;
            $retEax = (($bound & 0xFFFFFF00) | $byte) & 0xFFFFFFFF;

            $ma->writeBySize(RegisterType::ESI, $esi, 32);
        }

        $ma->writePhysical32($probAddr, $prob & 0xFFFFFFFF);
        $ma->writePhysical32($rangeAddr, $range & 0xFFFFFFFF);
        $ma->writePhysical32($codeAddr, $code & 0xFFFFFFFF);

        return [$bit & 1, $retEax & 0xFFFFFFFF, $probAddr & 0xFFFFFFFF];
    }
}
