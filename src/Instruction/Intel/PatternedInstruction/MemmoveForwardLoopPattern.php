<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\PagedMemoryStream;

/**
 * Pattern: byte-wise forward copy loop (memmove-style)
 *
 * 39 C7             cmp edi, eax
 * 74 xx             jz  <exit>
 * A4                movsb
 * EB yy             jmp <loop>
 *
 * This appears in GRUB's memmove implementation when copying forward.
 * The optimized implementation bulk-copies the remaining bytes and jumps to the
 * exit with the same final register/flag state as the original loop.
 */
class MemmoveForwardLoopPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'MEMMOVE forward byte loop';
    }

    public function priority(): int
    {
        return 145;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 7) {
            return null;
        }

        // cmp edi, eax
        if ($bytes[0] !== 0x39 || $bytes[1] !== 0xC7) {
            return null;
        }

        // jz rel8
        if ($bytes[2] !== 0x74) {
            return null;
        }

        // movsb
        if ($bytes[4] !== 0xA4) {
            return null;
        }

        // jmp rel8
        if ($bytes[5] !== 0xEB) {
            return null;
        }

        $jzRel = self::signExtend8($bytes[3]);
        $jmpRel = self::signExtend8($bytes[6]);

        // Validate backward jump to loop head.
        $loopTarget = ($ip + 7 + $jmpRel) & 0xFFFFFFFF;
        if ($loopTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        // jz is at ip+2, next ip is ip+4.
        $exitIp = ($ip + 4 + $jzRel) & 0xFFFFFFFF;

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, $exitIp, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode() || $cpu->addressSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }

            // Forward copy only when DF=0.
            $ma = $runtime->memoryAccessor();
            if ($ma->shouldDirectionFlag()) {
                return PatternedInstructionResult::skip($ip);
            }

            $memory = $runtime->memory();

            $end = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $dstOff = $ma->fetch(RegisterType::EDI)->asBytesBySize(32) & 0xFFFFFFFF;
            $srcOff = $ma->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;

            $count = ($end - $dstOff) & 0xFFFFFFFF;
            if ($count === 0) {
                // Flags from `cmp edi, eax` when equal.
                $ma->setCarryFlag(false);
                $ma->setZeroFlag(true);
                $ma->setSignFlag(false);
                $ma->setOverflowFlag(false);
                $ma->setParityFlag(true);
                $ma->setAuxiliaryCarryFlag(false);

                $runtime->context()->cpu()->clearTransientOverrides();
                $memory->setOffset($exitIp);
                return PatternedInstructionResult::success($exitIp);
            }

            $ds = self::resolveSegmentBaseLimit($runtime, RegisterType::DS);
            $es = self::resolveSegmentBaseLimit($runtime, RegisterType::ES);
            if ($ds === null || $es === null) {
                return PatternedInstructionResult::skip($ip);
            }
            [$dsBase, $dsLimit] = $ds;
            [$esBase, $esLimit] = $es;

            if ($srcOff + $count - 1 > $dsLimit || $dstOff + $count - 1 > $esLimit) {
                return PatternedInstructionResult::skip($ip);
            }

            $linearMask = self::linearMask($runtime);
            $srcLinear = ($dsBase + $srcOff) & $linearMask;
            $dstLinear = ($esBase + $dstOff) & $linearMask;

            // Avoid MMIO and wrap-around edge cases.
            if ($srcLinear < 0 || $dstLinear < 0) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($srcLinear + $count - 1 >= 0xE0000000 || $dstLinear + $count - 1 >= 0xE0000000) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($linearMask === 0xFFFFF) {
                if ($srcLinear + $count - 1 > 0xFFFFF || $dstLinear + $count - 1 > 0xFFFFF) {
                    return PatternedInstructionResult::skip($ip);
                }
            }

            // Preserve forward-copy semantics: skip overlap cases where memmove would reverse direction.
            if ($dstLinear > $srcLinear && $dstLinear < ($srcLinear + $count)) {
                return PatternedInstructionResult::skip($ip);
            }

            if ($cpu->isPagingEnabled()) {
                $isUser = $cpu->cpl() === 3;
                $isMmio = static function (int $addr): bool {
                    return ($addr >= 0xE0000000 && $addr < 0xE1000000) ||
                        ($addr >= 0xFEE00000 && $addr < 0xFEE01000) ||
                        ($addr >= 0xFEC00000 && $addr < 0xFEC00020);
                };

                $remaining = $count;
                $srcPtr = $srcLinear;
                $dstPtr = $dstLinear;
                /** @var array<int, array{int,int,int}> */
                $segments = [];

                while ($remaining > 0) {
                    $srcPageOff = $srcPtr & 0xFFF;
                    $dstPageOff = $dstPtr & 0xFFF;
                    $chunk = min($remaining, min(0x1000 - $srcPageOff, 0x1000 - $dstPageOff));

                    [$srcPhys, $srcErr] = $ma->translateLinear($srcPtr & 0xFFFFFFFF, false, $isUser, true, $linearMask);
                    if ($srcErr !== 0) {
                        return PatternedInstructionResult::skip($ip);
                    }
                    [$dstPhys, $dstErr] = $ma->translateLinear($dstPtr & 0xFFFFFFFF, true, $isUser, true, $linearMask);
                    if ($dstErr !== 0) {
                        return PatternedInstructionResult::skip($ip);
                    }

                    $srcPhys32 = ((int) $srcPhys) & 0xFFFFFFFF;
                    $dstPhys32 = ((int) $dstPhys) & 0xFFFFFFFF;
                    if ($isMmio($srcPhys32) || $isMmio($dstPhys32)) {
                        return PatternedInstructionResult::skip($ip);
                    }
                    if (self::rangeOverlapsObserverMemory($dstPhys32, $chunk)) {
                        return PatternedInstructionResult::skip($ip);
                    }

                    if (!$memory->ensureCapacity($srcPhys32 + $chunk)) {
                        return PatternedInstructionResult::skip($ip);
                    }
                    if (!$memory->ensureCapacity($dstPhys32 + $chunk)) {
                        return PatternedInstructionResult::skip($ip);
                    }

                    $segments[] = [$srcPhys32, $dstPhys32, $chunk];

                    $srcPtr = ($srcPtr + $chunk) & 0xFFFFFFFF;
                    $dstPtr = ($dstPtr + $chunk) & 0xFFFFFFFF;
                    $remaining -= $chunk;
                }

                $physicalMemory = $memory instanceof PagedMemoryStream ? $memory->physicalStream() : $memory;
                foreach ($segments as [$srcPhys32, $dstPhys32, $chunk]) {
                    $physicalMemory->copy($physicalMemory, $srcPhys32, $dstPhys32, $chunk);
                }
            } else {
                if (self::rangeOverlapsObserverMemory($dstLinear, $count)) {
                    return PatternedInstructionResult::skip($ip);
                }
                if (!$memory->ensureCapacity($srcLinear + $count)) {
                    return PatternedInstructionResult::skip($ip);
                }
                if (!$memory->ensureCapacity($dstLinear + $count)) {
                    return PatternedInstructionResult::skip($ip);
                }
                $memory->copy($memory, $srcLinear, $dstLinear, $count);
            }

            $ma->writeBySize(RegisterType::ESI, ($srcOff + $count) & 0xFFFFFFFF, 32);
            $ma->writeBySize(RegisterType::EDI, $end, 32);

            if (!$logged) {
                $logged = true;
                if ($this->traceHotPatterns) {
                    $runtime->option()->logger()->warning(sprintf(
                        'HOT PATTERN exec: %s ip=0x%08X src=0x%08X dst=0x%08X bytes=%d',
                        $patternName,
                        $ip & 0xFFFFFFFF,
                        ($dsBase + $srcOff) & 0xFFFFFFFF,
                        ($esBase + $dstOff) & 0xFFFFFFFF,
                        $count,
                    ));
                }
            }

            // Flags from final `cmp edi, eax` when equal.
            $ma->setCarryFlag(false);
            $ma->setZeroFlag(true);
            $ma->setSignFlag(false);
            $ma->setOverflowFlag(false);
            $ma->setParityFlag(true);
            $ma->setAuxiliaryCarryFlag(false);

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

        $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
        $descriptor = self::readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null || !($descriptor['present'] ?? false)) {
            return null;
        }
        return [(int) $descriptor['base'], (int) $descriptor['limit']];
    }

    private static function linearMask(RuntimeInterface $runtime): int
    {
        $cpu = $runtime->context()->cpu();
        if ($cpu->isLongMode()) {
            return 0x0000FFFFFFFFFFFF;
        }
        return $cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
    }

    /**
     * Minimal segment descriptor reader (GDT/LDT) for protected mode.
     *
     * @return array{base:int,limit:int,present:bool}|null
     */
    private static function readSegmentDescriptor(RuntimeInterface $runtime, int $selector): ?array
    {
        $cpu = $runtime->context()->cpu();

        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $cpu->ldtr();
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
            $tableBase = (int) ($ldtr['base'] ?? 0);
            $tableLimit = (int) ($ldtr['limit'] ?? 0);
        } else {
            $gdtr = $cpu->gdtr();
            $tableBase = (int) ($gdtr['base'] ?? 0);
            $tableLimit = (int) ($gdtr['limit'] ?? 0);
        }

        $index = ($selector >> 3) & 0x1FFF;
        $offset = $tableBase + ($index * 8);
        if (($offset + 7) > ($tableBase + $tableLimit)) {
            return null;
        }

        $ma = $runtime->memoryAccessor();
        $descLow = $ma->readPhysical32($offset);
        $descHigh = $ma->readPhysical32($offset + 4);

        $limitLow = $descLow & 0xFFFF;
        $baseLow = ($descLow >> 16) & 0xFFFF;
        $baseMid = $descHigh & 0xFF;
        $access = ($descHigh >> 8) & 0xFF;
        $gran = ($descHigh >> 16) & 0xFF;
        $baseHigh = ($descHigh >> 24) & 0xFF;

        $limitHigh = $gran & 0x0F;
        $fullLimit = $limitLow | ($limitHigh << 16);
        if (($gran & 0x80) !== 0) {
            $fullLimit = ($fullLimit << 12) | 0xFFF;
        }

        $baseAddr = $baseLow | ($baseMid << 16) | ($baseHigh << 24);
        $present = ($access & 0x80) !== 0;

        return [
            'base' => $baseAddr & 0xFFFFFFFF,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
        ];
    }
}
