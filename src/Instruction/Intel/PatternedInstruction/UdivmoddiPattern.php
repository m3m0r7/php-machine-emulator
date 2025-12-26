<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * Pattern: GCC-style unsigned 64-bit division helper (udivmoddi).
 *
 * This function takes:
 *   - numerator in EDX:EAX (unsigned 64-bit)
 *   - denominator in stack args (low/high)
 *   - remainder pointer in stack arg (optional, 0 = skip)
 * Returns quotient in EDX:EAX and optionally stores remainder to *ptr.
 *
 * We detect the full prologue and early setup sequence and replace the
 * whole helper with a direct 64-bit division in PHP for speed.
 */
final class UdivmoddiPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'udivmoddi helper';
    }

    public function priority(): int
    {
        return 210;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        $expected = [
            0x55,                         // push ebp
            0x89, 0xE5,                   // mov ebp, esp
            0x57,                         // push edi
            0x56,                         // push esi
            0x83, 0xEC, 0x20,             // sub esp, 0x20
            0x8D, 0x7D, 0x08,             // lea edi, [ebp+0x8]
            0x89, 0xF9,                   // mov ecx, edi
            0x8B, 0x37,                   // mov esi, [edi]
            0x8B, 0x7F, 0x04,             // mov edi, [edi+0x4]
            0x89, 0x75, 0xF0,             // mov [ebp-0x10], esi
            0x89, 0x7D, 0xF4,             // mov [ebp-0xc], edi
            0x8B, 0x49, 0x08,             // mov ecx, [ecx+0x8]
            0xC7, 0x45, 0xE0, 0x01, 0x00, 0x00, 0x00, // mov dword [ebp-0x20], 1
            0xC7, 0x45, 0xE4, 0x00, 0x00, 0x00, 0x00, // mov dword [ebp-0x1c], 0
            0x09, 0xF7,                   // or edi, esi
            0x75, 0x1D,                   // jnz +0x1d
            0xCD, 0x00,                   // int 0
            0x31, 0xF6,                   // xor esi, esi
            0x31, 0xFF,                   // xor edi, edi
            0xE9, 0x89, 0x00, 0x00, 0x00, // jmp +0x89
        ];

        if (count($bytes) < count($expected)) {
            return null;
        }

        for ($i = 0; $i < count($expected); $i++) {
            if (($bytes[$i] ?? null) !== $expected[$i]) {
                return null;
            }
        }

        $logged = false;
        $patternName = $this->name();

        return function (RuntimeInterface $runtime) use ($ip, &$logged, $patternName): PatternedInstructionResult {
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

            $ss = self::cachedSegmentBaseLimit($runtime, RegisterType::SS);
            if ($ss === null) {
                return PatternedInstructionResult::skip($ip);
            }
            [$ssBase, $ssLimit] = $ss;

            $esp = $ma->fetch(RegisterType::ESP)->asBytesBySize(32) & 0xFFFFFFFF;
            $arg0Off = ($esp + 4) & 0xFFFFFFFF;
            $arg1Off = ($esp + 8) & 0xFFFFFFFF;
            $arg2Off = ($esp + 12) & 0xFFFFFFFF;

            if ($arg2Off > $ssLimit || ($arg2Off + 3) > $ssLimit) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($arg1Off > $ssLimit || ($arg1Off + 3) > $ssLimit) {
                return PatternedInstructionResult::skip($ip);
            }
            if ($arg0Off > $ssLimit || ($arg0Off + 3) > $ssLimit) {
                return PatternedInstructionResult::skip($ip);
            }

            $denLow = $ma->readPhysical32(($ssBase + $arg0Off) & 0xFFFFFFFF) & 0xFFFFFFFF;
            $denHigh = $ma->readPhysical32(($ssBase + $arg1Off) & 0xFFFFFFFF) & 0xFFFFFFFF;
            $remPtr = $ma->readPhysical32(($ssBase + $arg2Off) & 0xFFFFFFFF) & 0xFFFFFFFF;

            $den = UInt64::fromParts($denLow, $denHigh);
            if ($den->isZero()) {
                return PatternedInstructionResult::skip($ip);
            }

            $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;
            $num = UInt64::fromParts($eax, $edx);

            $quot = $num->div($den);
            $rem = $num->mod($den);

            if ($remPtr !== 0) {
                $ds = self::cachedSegmentBaseLimit($runtime, RegisterType::DS);
                if ($ds === null) {
                    return PatternedInstructionResult::skip($ip);
                }
                [$dsBase, $dsLimit] = $ds;
                $remOff = $remPtr & 0xFFFFFFFF;
                if ($remOff > $dsLimit || ($remOff + 7) > $dsLimit) {
                    return PatternedInstructionResult::skip($ip);
                }
                $remPhys = ($dsBase + $remOff) & 0xFFFFFFFF;
                $ma->writePhysical32($remPhys, $rem->low32());
                $ma->writePhysical32(($remPhys + 4) & 0xFFFFFFFF, $rem->high32());
            }

            $ma->writeBySize(RegisterType::EAX, $quot->low32(), 32);
            $ma->writeBySize(RegisterType::EDX, $quot->high32(), 32);
            $ma->writeBySize(RegisterType::ECX, $remPtr, 32);

            if (!$logged && $this->traceHotPatterns) {
                $logged = true;
                $runtime->option()->logger()->warning(sprintf(
                    'HOT PATTERN exec: %s ip=0x%08X',
                    $patternName,
                    $ip & 0xFFFFFFFF,
                ));
            }

            $retOffset = $ma->pop(RegisterType::ESP, 32)->asBytesBySize(32) & 0xFFFFFFFF;
            $runtime->context()->cpu()->clearTransientOverrides();
            $memory->setOffset($retOffset);
            return PatternedInstructionResult::success($retOffset);
        };
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
