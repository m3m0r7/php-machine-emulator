<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: GRUB LZMA bit-tree decode function (returns EDX=value, ECX=bitmask).
 *
 * This is a helper routine used by the LZMA decoder to decode symbols of a
 * variable bit-width (CL). It calls the range-decode-bit routine (0x89ED)
 * in a tight LOOP and returns via RET.
 *
 * We inline the behavior for speed, including the final RET.
 */
final class LzmaBitTreeDecodePattern extends AbstractPatternedInstruction
{
    public function name(): string
    {
        return 'LZMA bit-tree decode function';
    }

    public function priority(): int
    {
        return 205;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 44) {
            return null;
        }

        // Exact function bytes at 0x8A39..0x8A64 (GRUB LZMA decoder).
        $expected = [
            0x0F, 0xB6, 0xC9,             // movzx ecx, cl
            0x31, 0xD2,                   // xor edx, edx
            0x52,                         // push edx
            0x42,                         // inc edx
            0x52,                         // push edx
            0x50,                         // push eax
            0x51,                         // push ecx
            0x52,                         // push edx
            0x01, 0xD0,                   // add eax, edx
            0xE8, 0xA2, 0xFF, 0xFF, 0xFF, // call 0x89ED
            0x5A,                         // pop edx
            0x59,                         // pop ecx
            0x73, 0x09,                   // jnc 0x8A58
            0x8B, 0x44, 0x24, 0x04,       // mov eax, [esp+4]
            0x09, 0x44, 0x24, 0x08,       // or [esp+8], eax
            0xF9,                         // stc
            0x11, 0xD2,                   // adc edx, edx
            0x58,                         // pop eax
            0xD1, 0x24, 0x24,             // shl dword [esp], 1
            0xE2, 0xE1,                   // loop 0x8A41
            0x59,                         // pop ecx
            0x29, 0xCA,                   // sub edx, ecx
            0x59,                         // pop ecx
            0xC3,                         // ret
        ];

        for ($i = 0; $i < count($expected); $i++) {
            if (($bytes[$i] ?? null) !== $expected[$i]) {
                return null;
            }
        }

        // Validate the embedded call target is 0x89ED.
        // call rel32 starts at ip+13, next ip is ip+18.
        $rel = ($bytes[14] & 0xFF)
            | (($bytes[15] & 0xFF) << 8)
            | (($bytes[16] & 0xFF) << 16)
            | (($bytes[17] & 0xFF) << 24);
        if (($rel & 0x80000000) !== 0) {
            $rel -= 0x1_0000_0000;
        }
        $callTarget = ($ip + 18 + $rel) & 0xFFFFFFFF;
        if ($callTarget !== 0x000089ED) {
            return null;
        }

        // Validate loop target (E2 rel8 at ip+37).
        // loop rel8 starts at ip+37, next ip is ip+39.
        $loopRel = self::signExtend8($bytes[38]);
        $loopTarget = ($ip + 39 + $loopRel) & 0xFFFFFFFF;
        if ($loopTarget !== (($ip + 8) & 0xFFFFFFFF)) {
            return null;
        }

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode() || $cpu->addressSize() !== 32 || $cpu->operandSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($cpu->isPagingEnabled() || !$cpu->isA20Enabled()) {
                return PatternedInstructionResult::skip($ip);
            }

            $ma = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            $baseIndex = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $count = $ma->fetch(RegisterType::ECX)->asLowBit() & 0xFF; // CL

            $symbol = 1;
            $mask = 1;
            $accum = 0;

            $linearMask = 0xFFFFFFFF;
            for ($i = 0; $i < $count; $i++) {
                $index = ($baseIndex + $symbol) & 0xFFFFFFFF;
                [$bit] = $this->lzmaRangeDecodeBit($runtime, $index, $linearMask);

                if ($bit !== 0) {
                    $accum = ($accum | $mask) & 0xFFFFFFFF;
                }

                $symbol = (($symbol << 1) | $bit) & 0xFFFFFFFF;
                $mask = ($mask << 1) & 0xFFFFFFFF;
            }

            $edxOut = ($symbol - $mask) & 0xFFFFFFFF;
            $ma->writeBySize(RegisterType::EDX, $edxOut, 32);
            $ma->writeBySize(RegisterType::ECX, $accum & 0xFFFFFFFF, 32);
            $ma->writeBySize(RegisterType::EAX, $baseIndex, 32);

            // Flags from `sub edx, mask` (mask is 1<<count).
            $this->setSub32Flags($ma, $symbol, $mask, $edxOut);

            if (!$logged) {
                $logged = true;
                $env = getenv('PHPME_TRACE_HOT_PATTERNS');
                if ($env !== false && trim($env) !== '' && trim($env) !== '0') {
                    $runtime->option()->logger()->warning(sprintf(
                        'HOT PATTERN exec: %s ip=0x%08X bits=%d',
                        $patternName,
                        $ip & 0xFFFFFFFF,
                        $count,
                    ));
                }
            }

            // RET (near, 32-bit): pop return offset and jump.
            $retOffset = $ma->pop(RegisterType::ESP, 32)->asBytesBySize(32) & 0xFFFFFFFF;
            $runtime->context()->cpu()->clearTransientOverrides();
            $memory->setOffset($retOffset);
            return PatternedInstructionResult::success($retOffset);
        };
    }

    private static function signExtend8(int $imm): int
    {
        $imm &= 0xFF;
        return ($imm & 0x80) !== 0 ? ($imm - 0x100) : $imm;
    }

    private function setSub32Flags($ma, int $dest, int $src, int $result): void
    {
        $res = $result & 0xFFFFFFFF;
        $ma->setZeroFlag($res === 0);
        $ma->setSignFlag(($res & 0x80000000) !== 0);
        $ma->setParityFlag($this->calculateParity($res & 0xFF));

        $ma->setAuxiliaryCarryFlag((($dest ^ $src ^ $res) & 0x10) !== 0);

        $signA = ($dest >> 31) & 1;
        $signB = ($src >> 31) & 1;
        $signR = ($res >> 31) & 1;
        $ma->setOverflowFlag(($signA !== $signB) && ($signA !== $signR));

        $ma->setCarryFlag(($dest & 0xFFFFFFFF) < ($src & 0xFFFFFFFF));
    }

    /**
     * Inline implementation of range-decode-bit (0x89ED).
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
            $range = $bound & 0xFFFFFFFF;
            $prob = ($prob + ((0x800 - $prob) >> 5)) & 0xFFFFFFFF;
            $bit = 0;
        } else {
            $range = ($range - $bound) & 0xFFFFFFFF;
            $code = ($code - $bound) & 0xFFFFFFFF;
            $prob = ($prob - ($prob >> 5)) & 0xFFFFFFFF;
            $bit = 1;
        }

        if (($range & 0xFFFFFFFF) < 0x01000000) {
            $range = ($range << 8) & 0xFFFFFFFF;

            $byte = $ma->readPhysical8($esi) & 0xFF;
            $esi = ($esi + ($ma->shouldDirectionFlag() ? -1 : 1)) & 0xFFFFFFFF;

            $code = (($code << 8) & 0xFFFFFFFF) | $byte;

            $ma->writeBySize(RegisterType::ESI, $esi, 32);
        }

        $ma->writePhysical32($probAddr, $prob & 0xFFFFFFFF);
        $ma->writePhysical32($rangeAddr, $range & 0xFFFFFFFF);
        $ma->writePhysical32($codeAddr, $code & 0xFFFFFFFF);

        return [$bit, $bound & 0xFFFFFFFF, $probAddr & 0xFFFFFFFF];
    }
}
