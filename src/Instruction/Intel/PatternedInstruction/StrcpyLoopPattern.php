<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: simple strcpy-style loop copying bytes until NUL.
 *
 * 8A 1C 11          mov bl, [ecx+edx]
 * 88 1C 10          mov [eax+edx], bl
 * 42                inc edx
 * 84 DB             test bl, bl
 * 75 F5             jnz <loop>
 *
 * This is common in early boot loader libc-like helpers.
 * The optimized implementation copies the NUL-terminated string in bulk.
 */
class StrcpyLoopPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'strcpy loop';
    }

    public function priority(): int
    {
        return 130;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 11) {
            return null;
        }

        // mov bl, [ecx+edx]
        if ($bytes[0] !== 0x8A || $bytes[1] !== 0x1C || $bytes[2] !== 0x11) {
            return null;
        }

        // mov [eax+edx], bl
        if ($bytes[3] !== 0x88 || $bytes[4] !== 0x1C || $bytes[5] !== 0x10) {
            return null;
        }

        // inc edx
        if ($bytes[6] !== 0x42) {
            return null;
        }

        // test bl, bl
        if ($bytes[7] !== 0x84 || $bytes[8] !== 0xDB) {
            return null;
        }

        // jnz rel8
        if ($bytes[9] !== 0x75) {
            return null;
        }

        $jnzRel = self::signExtend8($bytes[10]);
        $loopTarget = ($ip + 11 + $jnzRel) & 0xFFFFFFFF;
        if ($loopTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        $exitIp = ($ip + 11) & 0xFFFFFFFF;
        $patternName = $this->name();
        $maxLen = 0x4000; // safety limit (16KiB)

        return function (RuntimeInterface $runtime) use ($ip, $exitIp, $patternName, $maxLen): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode() || $cpu->addressSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($cpu->isPagingEnabled()) {
                return PatternedInstructionResult::skip($ip);
            }

            $segment = $cpu->segmentOverride() ?? RegisterType::DS;
            $seg = self::resolveSegmentBaseLimit($runtime, $segment);
            if ($seg === null) {
                return PatternedInstructionResult::skip($ip);
            }
            [$segBase, $segLimit] = $seg;

            $ma = $runtime->memoryAccessor();
            $dstBase = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $srcBase = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;
            $index = $ma->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;

            $srcOff = ($srcBase + $index) & 0xFFFFFFFF;
            $dstOff = ($dstBase + $index) & 0xFFFFFFFF;

            $remainingSrc = ($segLimit - $srcOff + 1);
            $remainingDst = ($segLimit - $dstOff + 1);
            if ($remainingSrc <= 0 || $remainingDst <= 0) {
                return PatternedInstructionResult::skip($ip);
            }
            $maxScan = (int) min($maxLen, $remainingSrc, $remainingDst);

            $linearMask = self::linearMask($runtime);
            $srcLinear = ($segBase + $srcOff) & $linearMask;
            $dstLinear = ($segBase + $dstOff) & $linearMask;

            if ($srcLinear < 0 || $dstLinear < 0) {
                return PatternedInstructionResult::skip($ip);
            }

            // Avoid MMIO ranges.
            if ($srcLinear >= 0xE0000000 || $dstLinear >= 0xE0000000) {
                return PatternedInstructionResult::skip($ip);
            }

            $memory = $runtime->memory();
            if (!$memory->ensureCapacity($srcLinear + $maxScan) || !$memory->ensureCapacity($dstLinear + $maxScan)) {
                return PatternedInstructionResult::skip($ip);
            }

            $saved = $memory->offset();
            $memory->setOffset($srcLinear);
            $chunk = $memory->read($maxScan);
            $pos = strpos($chunk, "\x00");
            if ($pos === false) {
                $memory->setOffset($saved);
                return PatternedInstructionResult::skip($ip);
            }

            $copyLen = (int) $pos + 1; // include NUL
            if (self::rangeOverlapsObserverMemory($dstLinear, $copyLen)) {
                $memory->setOffset($saved);
                return PatternedInstructionResult::skip($ip);
            }
            $memory->setOffset($dstLinear);
            $memory->write(substr($chunk, 0, $copyLen));
            $memory->setOffset($saved);

            $ma->writeBySize(RegisterType::EDX, ($index + $copyLen) & 0xFFFFFFFF, 32);

            // BL was last loaded with NUL and tested: set low byte of EBX to 0.
            $ebx = $ma->fetch(RegisterType::EBX)->asBytesBySize(32) & 0xFFFFFFFF;
            $ma->writeBySize(RegisterType::EBX, ($ebx & 0xFFFFFF00), 32);

            // Flags from final `test bl, bl` where BL==0.
            $ma->setCarryFlag(false);
            $ma->setOverflowFlag(false);
            $ma->setZeroFlag(true);
            $ma->setSignFlag(false);
            $ma->setParityFlag(true);
            $ma->setAuxiliaryCarryFlag(false);

            $runtime->context()->cpu()->clearTransientOverrides();

            if ($this->traceHotPatterns) {
                $runtime->option()->logger()->warning(sprintf(
                    'HOT PATTERN exec: %s ip=0x%08X src=0x%08X dst=0x%08X bytes=%d',
                    $patternName,
                    $ip & 0xFFFFFFFF,
                    $srcLinear & 0xFFFFFFFF,
                    $dstLinear & 0xFFFFFFFF,
                    $copyLen,
                ));
            }

            $memory->setOffset($exitIp);
            return PatternedInstructionResult::success($exitIp);
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
    private static function resolveSegmentBaseLimit(RuntimeInterface $runtime, RegisterType $segment): ?array
    {
        $cpu = $runtime->context()->cpu();
        $cached = $cpu->getCachedSegmentDescriptor($segment);
        if ($cached !== null) {
            $base = (int) ($cached['base'] ?? 0);
            $limit = (int) ($cached['limit'] ?? 0xFFFFFFFF);
            $present = (bool) ($cached['present'] ?? true);
            return $present ? [$base, $limit] : null;
        }

        if (!$cpu->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
            $base = (($selector << 4) & 0xFFFFF);
            return [$base, 0xFFFF];
        }

        return null;
    }

    private static function linearMask(RuntimeInterface $runtime): int
    {
        $cpu = $runtime->context()->cpu();
        if ($cpu->isLongMode()) {
            return 0x0000FFFFFFFFFFFF;
        }
        return $cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
    }
}
