<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: byte memset loop body that writes two bytes per iteration.
 */
final class MemsetBytePairLoopPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'memset (byte pair loop body)';
    }

    public function priority(): int
    {
        return 185;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        if (count($bytes) < 12) {
            return null;
        }

        $expected = [
            0x88, 0x10,             // mov [eax], dl
            0x88, 0x50, 0x01,       // mov [eax+1], dl
            0x83, 0xC0, 0x02,       // add eax, 2
            0x39, 0xC8,             // cmp eax, ecx
            0x75, 0x00,             // jnz rel8
        ];

        for ($i = 0; $i < 10; $i++) {
            if (($bytes[$i] ?? null) !== $expected[$i]) {
                return null;
            }
        }
        if (($bytes[10] ?? null) !== 0x75) {
            return null;
        }

        $rel = self::signExtend8($bytes[11]);
        $loopTarget = ($ip + 12 + $rel) & 0xFFFFFFFF;
        if ($loopTarget !== ($ip & 0xFFFFFFFF)) {
            return null;
        }

        $epilogueIp = ($ip + 12) & 0xFFFFFFFF;
        $patternName = $this->name();
        $logged = false;

        return function (RuntimeInterface $runtime) use ($ip, $epilogueIp, $patternName, &$logged): PatternedInstructionResult {
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
            if ($ds === null) {
                return PatternedInstructionResult::skip($ip);
            }
            [$dsBase, $dsLimit] = $ds;

            $eax0 = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $ecx0 = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;
            $edx0 = $ma->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;

            if ($ecx0 < $eax0) {
                return PatternedInstructionResult::skip($ip);
            }
            $len = ($ecx0 - $eax0) & 0xFFFFFFFF;
            if ($len === 0) {
                $ma->setCarryFlag(false);
                $ma->setZeroFlag(true);
                $ma->setSignFlag(false);
                $ma->setOverflowFlag(false);
                $ma->setParityFlag(true);
                $ma->setAuxiliaryCarryFlag(false);
                $runtime->context()->cpu()->clearTransientOverrides();
                $memory->setOffset($epilogueIp);
                return PatternedInstructionResult::success($epilogueIp);
            }
            if (($len & 1) !== 0) {
                return PatternedInstructionResult::skip($ip);
            }

            $endOff = ($eax0 + $len - 1) & 0xFFFFFFFF;
            if ($endOff < $eax0) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($eax0 > $dsLimit || $endOff > $dsLimit) {
                return PatternedInstructionResult::skip($ip);
            }

            $linearMask = $cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
            $dstLinear = ($dsBase + $eax0) & $linearMask;
            $dstPhys = $dstLinear & 0xFFFFFFFF;

            $isMmio = static fn (int $phys): bool => $phys >= 0xE0000000 && $phys < 0xE1000000;
            if ($isMmio($dstPhys) || $isMmio(($dstPhys + $len - 1) & 0xFFFFFFFF)) {
                return PatternedInstructionResult::skip($ip);
            }
            if (self::rangeOverlapsObserverMemory($dstPhys, $len)) {
                return PatternedInstructionResult::skip($ip);
            }
            if (!$memory->ensureCapacity($dstPhys + $len)) {
                return PatternedInstructionResult::skip($ip);
            }

            $byte = chr($edx0 & 0xFF);
            $chunk = 64 * 1024;
            $written = 0;
            while ($written < $len) {
                $n = (int) min($chunk, $len - $written);
                $memory->setOffset(($dstPhys + $written) & 0xFFFFFFFF);
                $memory->write(str_repeat($byte, $n));
                $written += $n;
            }

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
                        'HOT PATTERN exec: %s ip=0x%08X len=%d',
                        $patternName,
                        $ip & 0xFFFFFFFF,
                        $len,
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
