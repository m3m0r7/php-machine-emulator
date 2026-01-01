<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\PagedMemoryStream;

/**
 * Pattern: byte-wise backward copy loop (memmove-style)
 *
 * 83 E9 01          sub ecx, 1
 * 72 xx             jc  <exit>
 * 8A 14 0E          mov dl, [esi+ecx]
 * 88 14 08          mov [eax+ecx], dl
 * EB yy             jmp <loop>
 *
 * This shows up as a hot path in early boot code (e.g. GRUB font load) and can
 * dominate runtime if executed instruction-by-instruction in PHP.
 *
 * The optimized implementation bulk-copies the remaining bytes and jumps to the
 * loop exit with the same final register/flag state as the original loop.
 */
class MemmoveBackwardLoopPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'MEMMOVE backward byte loop';
    }

    public function priority(): int
    {
        return 150;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 13) {
            return null;
        }

        // sub ecx, 1
        if ($bytes[0] !== 0x83 || $bytes[1] !== 0xE9 || $bytes[2] !== 0x01) {
            return null;
        }

        // jc rel8
        if ($bytes[3] !== 0x72) {
            return null;
        }

        // mov dl, [esi+ecx]
        if ($bytes[5] !== 0x8A || $bytes[6] !== 0x14 || $bytes[7] !== 0x0E) {
            return null;
        }

        // mov [eax+ecx], dl
        if ($bytes[8] !== 0x88 || $bytes[9] !== 0x14 || $bytes[10] !== 0x08) {
            return null;
        }

        // jmp rel8
        if ($bytes[11] !== 0xEB) {
            return null;
        }

        $jcRel = self::signExtend8($bytes[4]);
        $jmpRel = self::signExtend8($bytes[12]);

        // Validate that the backward jump returns to the start of this loop.
        // jmp target = (ip + 13) + rel8
        $loopTarget = ($ip + 13 + $jmpRel) & 0xFFFFFFFF;
        if ($loopTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        // jc is at ip+3, next ip is ip+5.
        $exitIp = ($ip + 5 + $jcRel) & 0xFFFFFFFF;

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, $exitIp, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();

            if ($cpu->isLongMode() || $cpu->addressSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }

            $ma = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            $count = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;
            $srcOff = $ma->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;
            $dstOff = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;

            $ds = self::resolveSegmentBaseLimit($runtime, RegisterType::DS);
            if ($ds === null) {
                return PatternedInstructionResult::skip($ip);
            }
            [$dsBase, $dsLimit] = $ds;

            if ($count > 0) {
                $maxOff = max($srcOff, $dstOff) + ($count - 1);
                if ($maxOff > $dsLimit) {
                    return PatternedInstructionResult::skip($ip);
                }

                $linearMask = self::linearMask($runtime);
                $srcLinear = ($dsBase + $srcOff) & $linearMask;
                $dstLinear = ($dsBase + $dstOff) & $linearMask;

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

                if ($cpu->isPagingEnabled()) {
                    $isUser = $cpu->cpl() === 3;
                    $isMmio = static function (int $addr): bool {
                        return ($addr >= 0xE0000000 && $addr < 0xE1000000) ||
                            ($addr >= 0xFEE00000 && $addr < 0xFEE01000) ||
                            ($addr >= 0xFEC00000 && $addr < 0xFEC00020);
                    };
                    $remaining = $count;
                    /** @var array<int, array{int,int,int}> */
                    $segments = [];

                    while ($remaining > 0) {
                        $srcEnd = ($srcLinear + $remaining - 1) & 0xFFFFFFFF;
                        $dstEnd = ($dstLinear + $remaining - 1) & 0xFFFFFFFF;

                        $srcPageOff = $srcEnd & 0xFFF;
                        $dstPageOff = $dstEnd & 0xFFF;
                        $chunk = min($remaining, min($srcPageOff, $dstPageOff) + 1);

                        $srcStart = ($srcEnd - $chunk + 1) & 0xFFFFFFFF;
                        $dstStart = ($dstEnd - $chunk + 1) & 0xFFFFFFFF;

                        [$srcPhys, $srcErr] = $ma->translateLinear($srcStart, false, $isUser, true, $linearMask);
                        if ($srcErr !== 0) {
                            return PatternedInstructionResult::skip($ip);
                        }
                        [$dstPhys, $dstErr] = $ma->translateLinear($dstStart, true, $isUser, true, $linearMask);
                        if ($dstErr !== 0) {
                            return PatternedInstructionResult::skip($ip);
                        }

                        $srcPhysInt = (int) $srcPhys;
                        $dstPhysInt = (int) $dstPhys;
                        if ($srcPhysInt < 0 || $dstPhysInt < 0) {
                            return PatternedInstructionResult::skip($ip);
                        }

                        // Refuse known MMIO physical ranges (LFB/APIC/IOAPIC).
                        $srcPhys32 = $srcPhysInt & 0xFFFFFFFF;
                        $dstPhys32 = $dstPhysInt & 0xFFFFFFFF;
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
                        $remaining -= $chunk;
                    }

                    $physicalMemory = $memory instanceof PagedMemoryStream ? $memory->physicalStream() : $memory;
                    foreach ($segments as [$srcPhys32, $dstPhys32, $chunk]) {
                        $physicalMemory->copy($physicalMemory, $srcPhys32, $dstPhys32, $chunk);
                    }

                    // Emulate DL clobber from the last iteration: DL ends with src[0].
                    [$srcPhys0, $srcErr0] = $ma->translateLinear($srcLinear & 0xFFFFFFFF, false, $isUser, true, $linearMask);
                    if ($srcErr0 !== 0) {
                        return PatternedInstructionResult::skip($ip);
                    }
                    $first = $ma->readPhysical8(((int) $srcPhys0) & 0xFFFFFFFF) & 0xFF;
                } else {
                    // Ensure both ranges exist. The emulator expands reads with zero-fill,
                    // so we must also extend the source region for bulk copy.
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

                    // Emulate DL clobber from the last iteration: DL ends with src[0].
                    $first = $ma->readPhysical8($srcLinear) & 0xFF;
                }

                $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
                $ma->writeBySize(RegisterType::EDX, ($edx & 0xFFFFFF00) | $first, 32);
            }

            if (!$logged) {
                $logged = true;
                if ($this->traceHotPatterns) {
                    $runtime->option()->logger()->warning(sprintf(
                        'HOT PATTERN exec: %s ip=0x%08X src=0x%08X dst=0x%08X bytes=%d',
                        $patternName,
                        $ip & 0xFFFFFFFF,
                        ($dsBase + $srcOff) & 0xFFFFFFFF,
                        ($dsBase + $dstOff) & 0xFFFFFFFF,
                        $count,
                    ));
                }
            }

            // Final state after loop completes (ECX underflows in the terminal SUB):
            // ECX=0xFFFFFFFF and flags match `sub ecx,1` where ecx was 0.
            $ma->writeBySize(RegisterType::ECX, 0xFFFFFFFF, 32);
            $ma->setCarryFlag(true);
            $ma->setZeroFlag(false);
            $ma->setSignFlag(true);
            $ma->setOverflowFlag(false);
            $ma->setParityFlag(true);          // low byte 0xFF has even parity
            $ma->setAuxiliaryCarryFlag(true);  // borrow from bit 3 on 0 - 1

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
     * Resolve segment base/limit using CPU cache when available.
     *
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

        // Fallback for real mode without a cached descriptor.
        if (!$cpu->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
            $base = (($selector << 4) & 0xFFFFF);
            return [$base, 0xFFFF];
        }

        // Protected mode without cache: try to read the descriptor from GDT/LDT.
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
