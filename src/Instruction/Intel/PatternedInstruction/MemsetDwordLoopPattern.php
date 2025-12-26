<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: 32-bit memset-like routine (GRUB/bootloader hot loop).
 *
 * This matches a very common compiler-generated memset implementation that:
 * - Writes dwords in a tight loop while (end - ptr) > 3
 * - Then writes remaining bytes in a tight loop until ptr == end
 * - Restores registers and returns
 *
 * We accelerate the dword+byte filling portion and jump to the epilogue
 * (mov eax, ebx; pop...; ret), preserving flags as if the final `cmp eax, ecx`
 * saw equality.
 */
final class MemsetDwordLoopPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'memset (dword+byte) loop';
    }

    public function priority(): int
    {
        return 190;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        // Full sequence is 57 bytes long; PatternedInstructionsList provides up to 64 bytes.
        if (count($bytes) < 57) {
            return null;
        }

        // Fixed bytes (except two disp8 values and the rel8 immediates we validate separately).
        $expected = [
            0x89, 0xF7,             // mov edi, esi
            0x29, 0xD7,             // sub edi, edx
            0x83, 0xFF, 0x03,       // cmp edi, 3
            0x77, 0x00,             // ja  rel8
            0x89, 0xCA,             // mov edx, ecx
            0xC1, 0xEA, 0x02,       // shr edx, 2
            0x6B, 0xFA, 0xFC,       // imul edi, edx, -4
            0x01, 0xF9,             // add ecx, edi
            0x8D, 0x04, 0x90,       // lea eax, [eax + edx*4]
            0x01, 0xC1,             // add ecx, eax
            0xEB, 0x00,             // jmp rel8
            0x8B, 0x7D, 0x00,       // mov edi, [ebp+disp8]
            0x89, 0x3A,             // mov [edx], edi
            0x83, 0xC2, 0x04,       // add edx, 4
            0xEB, 0x00,             // jmp rel8
            0x39, 0xC8,             // cmp eax, ecx
            0x74, 0x00,             // jz  rel8
            0x8A, 0x55, 0x00,       // mov dl, [ebp+disp8]
            0x88, 0x10,             // mov [eax], dl
            0x40,                   // inc eax
            0xEB, 0x00,             // jmp rel8
            0x89, 0xD8,             // mov eax, ebx
            0x5A, 0x59, 0x5B, 0x5E, 0x5F, 0x5D, 0xC3, // pops + ret
        ];

        $mask = array_fill(0, count($expected), true);
        // ja rel8
        $mask[8] = false;
        // jmp rel8 to byte loop
        $mask[25] = false;
        // disp8 for [ebp+disp8] (dword fill)
        $mask[28] = false;
        // jmp rel8 back to loop head
        $mask[35] = false;
        // jz rel8 to epilogue
        $mask[39] = false;
        // disp8 for [ebp+disp8] (byte fill)
        $mask[42] = false;
        // jmp rel8 back to byte loop head
        $mask[47] = false;

        for ($i = 0; $i < count($expected); $i++) {
            if (!$mask[$i]) {
                continue;
            }
            if (($bytes[$i] ?? null) !== $expected[$i]) {
                return null;
            }
        }

        $jaRel = self::signExtend8($bytes[8]);
        $jmpToByteRel = self::signExtend8($bytes[25]);
        $jmpBackRel = self::signExtend8($bytes[35]);
        $jzRel = self::signExtend8($bytes[39]);
        $jmpByteBackRel = self::signExtend8($bytes[47]);

        $jaTarget = ($ip + 9 + $jaRel) & 0xFFFFFFFF;
        $dwordBodyIp = ($ip + 26) & 0xFFFFFFFF;
        if ($jaTarget !== $dwordBodyIp) {
            return null;
        }

        // jmp (at ip+24) targets the byte-loop head at ip+36.
        $jmpToByteTarget = ($ip + 26 + $jmpToByteRel) & 0xFFFFFFFF;
        $byteLoopIp = ($ip + 36) & 0xFFFFFFFF;
        if ($jmpToByteTarget !== $byteLoopIp) {
            return null;
        }

        // jmp (at ip+34) loops back to ip.
        $jmpBackTarget = ($ip + 36 + $jmpBackRel) & 0xFFFFFFFF;
        if ($jmpBackTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        // jz (at ip+38) targets the epilogue at ip+48.
        $jzTarget = ($ip + 40 + $jzRel) & 0xFFFFFFFF;
        $epilogueIp = ($ip + 48) & 0xFFFFFFFF;
        if ($jzTarget !== $epilogueIp) {
            return null;
        }

        // jmp (at ip+46) loops back to byte-loop head at ip+36.
        $jmpByteBackTarget = ($ip + 48 + $jmpByteBackRel) & 0xFFFFFFFF;
        if ($jmpByteBackTarget !== $byteLoopIp) {
            return null;
        }

        $fillDwordDisp = self::signExtend8($bytes[28]);
        $fillByteDisp = self::signExtend8($bytes[42]);

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, $epilogueIp, $fillDwordDisp, $fillByteDisp, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode() || $cpu->addressSize() !== 32 || $cpu->operandSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }
            if (!$cpu->isProtectedMode()) {
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

            $ds = self::cachedSegmentBaseLimit($runtime, RegisterType::DS);
            $ss = self::cachedSegmentBaseLimit($runtime, RegisterType::SS);
            if ($ds === null || $ss === null) {
                return PatternedInstructionResult::skip($ip);
            }
            [$dsBase, $dsLimit] = $ds;
            [$ssBase, $ssLimit] = $ss;

            $eax0 = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $ecx0 = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;
            $edx0 = $ma->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;
            $esi0 = $ma->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;
            $ebp0 = $ma->fetch(RegisterType::EBP)->asBytesBySize(32) & 0xFFFFFFFF;

            // Read fill values from SS:[EBP+disp].
            $fillDwordOff = ($ebp0 + $fillDwordDisp) & 0xFFFFFFFF;
            $fillByteOff = ($ebp0 + $fillByteDisp) & 0xFFFFFFFF;
            if ($fillDwordOff > $ssLimit || ($fillDwordOff + 3) > $ssLimit || $fillByteOff > $ssLimit) {
                return PatternedInstructionResult::skip($ip);
            }
            $fillDwordPhys = ($ssBase + $fillDwordOff) & 0xFFFFFFFF;
            $fillBytePhys = ($ssBase + $fillByteOff) & 0xFFFFFFFF;

            $fillDword = $ma->readPhysical32($fillDwordPhys) & 0xFFFFFFFF;
            $fillByte = $ma->readPhysical8($fillBytePhys) & 0xFF;

            // Dword loop writes DS:[EDX..ESI) in 4-byte steps.
            $len = ($esi0 - $edx0) & 0xFFFFFFFF;
            $dwordCount = ($len >> 2) & 0xFFFFFFFF;
            $dwordBytes = ($dwordCount * 4) & 0xFFFFFFFF;

            // Byte loop writes DS:[EAX + (ECX>>2)*4 .. EAX+ECX).
            $edxTail = ($ecx0 >> 2) & 0xFFFFFFFF;
            $tailStartOff = ($eax0 + (($edxTail << 2) & 0xFFFFFFFF)) & 0xFFFFFFFF;
            $tailEndOff = ($eax0 + $ecx0) & 0xFFFFFFFF;

            if ($tailEndOff < $tailStartOff) {
                return PatternedInstructionResult::skip($ip);
            }
            $tailLen = ($tailEndOff - $tailStartOff) & 0xFFFFFFFF;

            // Segment limit checks (offset-based).
            if ($edx0 > $dsLimit || ($dwordBytes > 0 && ($edx0 + $dwordBytes - 1) > $dsLimit)) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($tailStartOff > $dsLimit || ($tailLen > 0 && ($tailStartOff + $tailLen - 1) > $dsLimit)) {
                return PatternedInstructionResult::skip($ip);
            }

            $linearMask = $cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

            $isMmio = static fn (int $phys): bool => $phys >= 0xE0000000 && $phys < 0xE1000000;

            // Bulk dword fill
            if ($dwordBytes > 0) {
                $dstLinear = ($dsBase + $edx0) & $linearMask;
                $dstPhys = $dstLinear & 0xFFFFFFFF;
                if ($isMmio($dstPhys) || $isMmio(($dstPhys + $dwordBytes - 1) & 0xFFFFFFFF)) {
                    return PatternedInstructionResult::skip($ip);
                }
                if (self::rangeOverlapsObserverMemory($dstPhys, $dwordBytes)) {
                    return PatternedInstructionResult::skip($ip);
                }
                if (!$memory->ensureCapacity($dstPhys + $dwordBytes)) {
                    return PatternedInstructionResult::skip($ip);
                }

                $chunkDwords = 16384; // 64KB chunks
                $pattern = pack('V', $fillDword);
                $written = 0;
                $remaining = $dwordCount;
                while ($remaining > 0) {
                    $thisChunk = min($remaining, $chunkDwords);
                    $bytesChunk = $thisChunk * 4;
                    $memory->setOffset(($dstPhys + $written) & 0xFFFFFFFF);
                    $memory->write(str_repeat($pattern, $thisChunk));
                    $written += $bytesChunk;
                    $remaining -= $thisChunk;
                }
            }

            // Bulk byte tail fill
            if ($tailLen > 0) {
                $tailLinear = ($dsBase + $tailStartOff) & $linearMask;
                $tailPhys = $tailLinear & 0xFFFFFFFF;
                if ($isMmio($tailPhys) || $isMmio(($tailPhys + $tailLen - 1) & 0xFFFFFFFF)) {
                    return PatternedInstructionResult::skip($ip);
                }
                if (self::rangeOverlapsObserverMemory($tailPhys, $tailLen)) {
                    return PatternedInstructionResult::skip($ip);
                }
                if (!$memory->ensureCapacity($tailPhys + $tailLen)) {
                    return PatternedInstructionResult::skip($ip);
                }

                $chunk = 64 * 1024;
                $byte = chr($fillByte);
                $written = 0;
                while ($written < $tailLen) {
                    $n = (int) min($chunk, $tailLen - $written);
                    $memory->setOffset(($tailPhys + $written) & 0xFFFFFFFF);
                    $memory->write(str_repeat($byte, $n));
                    $written += $n;
                }
            }

            // State at the epilogue is as if the byte-loop `cmp eax, ecx` saw equality.
            $endPtr = $tailEndOff & 0xFFFFFFFF;
            $ma->writeBySize(RegisterType::EAX, $endPtr, 32);
            $ma->writeBySize(RegisterType::ECX, $endPtr, 32);
            // The byte loop loads DL only when it executes at least one iteration.
            // When tailLen==0, the `mov dl, [ebp+disp]` is skipped (jz taken immediately),
            // so EDX remains as computed by `shr edx, 2`.
            $edxOut = $edxTail & 0xFFFFFFFF;
            if ($tailLen > 0) {
                $edxOut = (($edxOut & 0xFFFFFF00) | ($fillByte & 0xFF)) & 0xFFFFFFFF;
            }
            $ma->writeBySize(RegisterType::EDX, $edxOut, 32);

            $ma->setCarryFlag(false);
            $ma->setZeroFlag(true);
            $ma->setSignFlag(false);
            $ma->setOverflowFlag(false);
            $ma->setParityFlag(true);
            $ma->setAuxiliaryCarryFlag(false);

            if (!$logged) {
                $logged = true;
                if ($this->traceHotPatterns) {
                    $runtime->option()->logger()->warning(sprintf(
                        'HOT PATTERN exec: %s ip=0x%08X dwordBytes=%d tailBytes=%d',
                        $patternName,
                        $ip & 0xFFFFFFFF,
                        $dwordBytes,
                        $tailLen,
                    ));
                }
            }

            $runtime->context()->cpu()->clearTransientOverrides();
            $memory->setOffset($epilogueIp);
            return PatternedInstructionResult::success($epilogueIp);
        };
    }

    private static function signExtend8(int $imm): int
    {
        $imm &= 0xFF;
        return ($imm & 0x80) !== 0 ? ($imm - 0x100) : $imm;
    }

    /**
     * @return array{int,int}|null [base, limit]
     */
    private static function cachedSegmentBaseLimit(RuntimeInterface $runtime, RegisterType $segment): ?array
    {
        $cpu = $runtime->context()->cpu();
        $cached = $cpu->getCachedSegmentDescriptor($segment);
        if ($cached === null) {
            return null;
        }
        $present = (bool) ($cached['present'] ?? true);
        if (!$present) {
            return null;
        }
        $base = (int) (($cached['base'] ?? 0) & 0xFFFFFFFF);
        $limit = (int) (($cached['limit'] ?? 0xFFFFFFFF) & 0xFFFFFFFF);
        return [$base, $limit];
    }
}
