<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: LZMA bit-tree decode loop building an 8-bit symbol in EDX.
 *
 * This shows up in GRUB's LZMA decoder as a tight loop:
 *
 *   cmp edx, 0x100
 *   jnc exit
 *   push edx
 *   mov eax, edx
 *   add eax, [esp+8]
 *   call 0x89ED   ; range-decode-bit
 *   pop edx
 *   adc edx, edx
 *   jmp loop
 *
 * Semantics: starting from edx<0x100 (typically 1), repeatedly decode a bit and
 * accumulate it into edx until edx>=0x100 (8 bits decoded). The decoded byte is DL.
 *
 * We inline the range-decode-bit algorithm for speed, while matching architectural
 * side-effects on registers/memory relevant to the decoder state.
 */
final class LzmaBitTreeDecodeBytePattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'LZMA bit-tree decode byte loop';
    }

    public function priority(): int
    {
        // Slightly above range-decode-bit; different IP anyway.
        return 210;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 25) {
            return null;
        }

        // cmp edx, 0x100
        if ($bytes[0] !== 0x81 || $bytes[1] !== 0xFA || $bytes[2] !== 0x00 || $bytes[3] !== 0x01 || $bytes[4] !== 0x00 || $bytes[5] !== 0x00) {
            return null;
        }

        // jnc rel8
        if ($bytes[6] !== 0x73) {
            return null;
        }

        // push edx
        if ($bytes[8] !== 0x52) {
            return null;
        }

        // mov eax, edx
        if ($bytes[9] !== 0x89 || $bytes[10] !== 0xD0) {
            return null;
        }

        // add eax, [esp+8]
        if ($bytes[11] !== 0x03 || $bytes[12] !== 0x44 || $bytes[13] !== 0x24 || $bytes[14] !== 0x08) {
            return null;
        }

        // call rel32
        if ($bytes[15] !== 0xE8) {
            return null;
        }

        // pop edx
        if ($bytes[20] !== 0x5A) {
            return null;
        }

        // adc edx, edx
        if ($bytes[21] !== 0x11 || $bytes[22] !== 0xD2) {
            return null;
        }

        // jmp rel8 (back to loop)
        if ($bytes[23] !== 0xEB) {
            return null;
        }

        $jncRel = self::signExtend8($bytes[7]);
        $jmpRel = self::signExtend8($bytes[24]);

        $exitIp = ($ip + 8 + $jncRel) & 0xFFFFFFFF;
        $loopTarget = ($ip + 25 + $jmpRel) & 0xFFFFFFFF;
        if ($loopTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        // Validate that the call targets the known range-decode-bit routine.
        $rel = ($bytes[16] & 0xFF)
            | (($bytes[17] & 0xFF) << 8)
            | (($bytes[18] & 0xFF) << 16)
            | (($bytes[19] & 0xFF) << 24);
        if (($rel & 0x80000000) !== 0) {
            $rel -= 0x1_0000_0000;
        }
        $callTarget = ($ip + 20 + $rel) & 0xFFFFFFFF;
        if ($callTarget !== 0x000089ED) {
            return null;
        }

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, $exitIp, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();

            // GRUB LZMA decoder runs in 32-bit protected mode without paging.
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
                // Flags from cmp edx, 0x100 (edx>=0x100 => CF=0).
                $this->setCmp32Flags($ma, $edx, 0x100);
                $runtime->context()->cpu()->clearTransientOverrides();
                $memory->setOffset($exitIp);
                return PatternedInstructionResult::success($exitIp);
            }

            $ssBase = self::resolveSegmentBase($runtime, RegisterType::SS);
            if ($ssBase === null) {
                return PatternedInstructionResult::skip($ip);
            }

            $esp = $ma->fetch(RegisterType::ESP)->asBytesBySize(32) & 0xFFFFFFFF;
            $stackLinear = ($ssBase + $esp) & 0xFFFFFFFF;

            // Base index is read as [esp+8] after `push edx`, i.e. at entry [esp+4].
            $baseIndex = $ma->readPhysical32(($stackLinear + 4) & 0xFFFFFFFF) & 0xFFFFFFFF;

            // Decode until we have 8 bits (edx>=0x100).
            $lastEax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $lastEcx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;

            $linearMask = 0xFFFFFFFF;
            while ($edx < 0x100) {
                $index = ($edx + $baseIndex) & 0xFFFFFFFF;
                [$bit, $retEax, $probAddr] = $this->lzmaRangeDecodeBit($runtime, $index, $linearMask);

                // Caller-visible clobbers: EAX (bound or bound with byte), ECX (prob pointer).
                $lastEax = $retEax;
                $lastEcx = $probAddr;

                $edx = (($edx << 1) | $bit) & 0xFFFFFFFF;
            }

            $ma->writeBySize(RegisterType::EDX, $edx, 32);
            $ma->writeBySize(RegisterType::EAX, $lastEax, 32);
            $ma->writeBySize(RegisterType::ECX, $lastEcx, 32);

            // Flags from the terminating cmp edx, 0x100.
            $this->setCmp32Flags($ma, $edx, 0x100);

            if (!$logged) {
                $logged = true;
                if ($this->traceHotPatterns) {
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

    private static function resolveSegmentBase(RuntimeInterface $runtime, RegisterType $segment): ?int
    {
        $cpu = $runtime->context()->cpu();
        $cached = $cpu->getCachedSegmentDescriptor($segment);
        if ($cached !== null) {
            $present = (bool) ($cached['present'] ?? true);
            if (!$present) {
                return null;
            }
            return (int) ($cached['base'] ?? 0);
        }

        if (!$cpu->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
            return (($selector << 4) & 0xFFFFF);
        }

        return null;
    }

    private function setCmp32Flags($ma, int $dest, int $src): void
    {
        $result = ($dest - $src) & 0xFFFFFFFF;

        $ma->setCarryFlag(($dest & 0xFFFFFFFF) < ($src & 0xFFFFFFFF));
        $ma->setZeroFlag($result === 0);
        $ma->setSignFlag(($result & 0x80000000) !== 0);
        $ma->setParityFlag($this->calculateParity($result & 0xFF));

        // AF from borrow between bit3/4.
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

        // Normalize if needed (range < 0x01000000).
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

        return [$bit, $retEax & 0xFFFFFFFF, $probAddr & 0xFFFFFFFF];
    }
}
