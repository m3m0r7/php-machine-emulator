<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Pattern: GRUB/LZMA range decoder "decode bit" inner routine (32-bit).
 *
 * This extremely hot routine appears during El Torito/GRUB core image decompression.
 * It implements a single range-decoder probability update + optional normalization,
 * returning the decoded bit in CF and then RETurns to the caller.
 *
 * We match the exact byte sequence starting at the function entry:
 *   8D 04 83              lea eax,[ebx+eax*4]
 *   89 C1                 mov ecx,eax
 *   8B 01                 mov eax,[ecx]
 *   8B 55 F4              mov edx,[ebp-0x0c]
 *   C1 EA 0B              shr edx,0xb
 *   ...                   (range decode bit, optional normalize, ret)
 *
 * The optimized implementation performs the equivalent updates in one closure and
 * executes the RET by popping the return offset from the stack.
 */
class LzmaRangeDecodeBitPattern extends AbstractPatternedInstruction
{
    private bool $traceHotPatterns;
    private ?array $segState = null;

    public function __construct(bool $traceHotPatterns = false)
    {
        $this->traceHotPatterns = $traceHotPatterns;
    }

    public function name(): string
    {
        return 'LZMA range decode bit';
    }

    public function priority(): int
    {
        return 160;
    }

    public function tryCompile(int $ip, array $bytes): ?callable
    {
        // Needs the fixed prefix; PatternedInstructionsList currently provides 64 bytes.
        if (count($bytes) < 64) {
            return null;
        }

        // Exact bytes captured from a GRUB core image (hot inner routine).
        $expected = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29,
        ];

        for ($i = 0; $i < count($expected); $i++) {
            if (($bytes[$i] ?? null) !== $expected[$i]) {
                return null;
            }
        }

        $patternName = $this->name();
        $logged = false;

        return function (RuntimeInterface $runtime) use ($ip, $patternName, &$logged): PatternedInstructionResult {
            $cpu = $runtime->context()->cpu();
            if ($cpu->isLongMode() || $cpu->addressSize() !== 32 || $cpu->operandSize() !== 32) {
                return PatternedInstructionResult::skip($ip);
            }
            if (!$cpu->isProtectedMode() || $cpu->isPagingEnabled() || !$cpu->isA20Enabled()) {
                return PatternedInstructionResult::skip($ip);
            }

            $ma = $runtime->memoryAccessor();
            $memory = $runtime->memory();

            // Require flat segments (base=0) for a fast/precise RET + data addressing.
            // Do not rely on the Unreal-mode cache always being populated; resolve via GDT/LDT as needed.
            $segState = $this->segState;
            $csSel = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
            $dsSel = $ma->fetch(RegisterType::DS)->asByte() & 0xFFFF;
            $ssSel = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;
            if (
                $segState === null
                || ($segState['csSel'] ?? null) !== $csSel
                || ($segState['dsSel'] ?? null) !== $dsSel
                || ($segState['ssSel'] ?? null) !== $ssSel
            ) {
                $csBase = $this->resolveSegmentBase($runtime, RegisterType::CS);
                $dsBase = $this->resolveSegmentBase($runtime, RegisterType::DS);
                $ssBase = $this->resolveSegmentBase($runtime, RegisterType::SS);
                $segState = [
                    'csSel' => $csSel,
                    'dsSel' => $dsSel,
                    'ssSel' => $ssSel,
                    'csBase' => $csBase,
                    'dsBase' => $dsBase,
                    'ssBase' => $ssBase,
                ];
                $this->segState = $segState;
            }
            $csBase = $segState['csBase'] ?? null;
            $dsBase = $segState['dsBase'] ?? null;
            $ssBase = $segState['ssBase'] ?? null;
            if ($csBase === null || $dsBase === null || $ssBase === null) {
                return PatternedInstructionResult::skip($ip);
            }
            if (($csBase | $dsBase | $ssBase) !== 0) {
                return PatternedInstructionResult::skip($ip);
            }

            $index = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
            $base = $ma->fetch(RegisterType::EBX)->asBytesBySize(32) & 0xFFFFFFFF;
            $ebp = $ma->fetch(RegisterType::EBP)->asBytesBySize(32) & 0xFFFFFFFF;
            $esi = $ma->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;

            $probPtr = ($base + (($index << 2) & 0xFFFFFFFF)) & 0xFFFFFFFF;
            $ma->writeBySize(RegisterType::ECX, $probPtr, 32);

            $rangeAddr = ($ebp - 0x0C) & 0xFFFFFFFF;
            $codeAddr = ($ebp - 0x10) & 0xFFFFFFFF;

            $range = $ma->readPhysical32($rangeAddr) & 0xFFFFFFFF;
            $code = $ma->readPhysical32($codeAddr) & 0xFFFFFFFF;

            $prob = $ma->readPhysical32($probPtr) & 0xFFFFFFFF;
            $rangeShift = ($range >> 11) & 0xFFFFFFFF;

            // bound = prob * (range>>11). In this GRUB routine, the product fits in 32-bit.
            $bound = ($prob * $rangeShift) & 0xFFFFFFFF;

            // Unsigned compare: if (code >= bound) -> bit=1 (else branch).
            // Values are masked to 32-bit and fit within PHP int, so a direct compare is safe.
            $bitOne = $code >= $bound;

            $delta = 0;
            $returnEax = $bound;
            if (!$bitOne) {
                // bit=0: range = bound
                $range = $bound;

                // prob += (0x800 - prob) >> 5
                $delta = ((0x800 - $prob) & 0xFFFFFFFF) >> 5;
                $newProb = ($prob + $delta) & 0xFFFFFFFF;
                $ma->writePhysical32($probPtr, $newProb);

                $this->updateAddFlags32($ma, $prob, $delta, $newProb);
                $ma->setCarryFlag(false); // CLC
            } else {
                // bit=1: range -= bound; code -= bound
                $range = ($range - $bound) & 0xFFFFFFFF;
                $code = ($code - $bound) & 0xFFFFFFFF;

                // prob -= prob >> 5
                $delta = ($prob >> 5) & 0xFFFFFFFF;
                $newProb = ($prob - $delta) & 0xFFFFFFFF;
                $ma->writePhysical32($probPtr, $newProb);

                $this->updateSubFlags32($ma, $prob, $delta, $newProb);
                $ma->setCarryFlag(true); // STC
            }

            // Normalize when range < 0x01000000.
            if (($range & 0xFFFFFFFF) < 0x01000000) {
                $range = ($range << 8) & 0xFFFFFFFF;

                $byte = $ma->readPhysical8($esi) & 0xFF;
                $esi = ($esi + ($ma->shouldDirectionFlag() ? -1 : 1)) & 0xFFFFFFFF;

                $code = (($code << 8) & 0xFFFFFFFF) | $byte;
                $ma->writeBySize(RegisterType::ESI, $esi, 32);

                $ma->writePhysical32($codeAddr, $code);
                $ma->writePhysical32($rangeAddr, $range);

                // Match LODSB side-effect: AL is overwritten by the byte read.
                $returnEax = (($bound & 0xFFFFFF00) | $byte) & 0xFFFFFFFF;
            } else {
                // Ensure memory reflects updates even when no normalization.
                $ma->writePhysical32($codeAddr, $code);
                $ma->writePhysical32($rangeAddr, $range);
            }

            $ma->writeBySize(RegisterType::EAX, $returnEax, 32);
            $ma->writeBySize(RegisterType::EDX, $delta & 0xFFFFFFFF, 32);

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

            // RET (near, 32-bit): pop return offset and jump.
            // Use MemoryAccessor::pop to honor SS default stack size (16/32) semantics.
            $retOffset = $ma->pop(RegisterType::ESP, 32)->asBytesBySize(32) & 0xFFFFFFFF;

            $runtime->context()->cpu()->clearTransientOverrides();
            // Flat CS base is enforced above (csBase==0), so linear IP == return offset.
            $memory->setOffset($retOffset);
            return PatternedInstructionResult::success($retOffset);
        };
    }

    private function resolveSegmentBase(RuntimeInterface $runtime, RegisterType $segment): ?int
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

        // Pattern is designed for protected mode (32-bit GRUB core), but keep a real-mode fallback for safety.
        if (!$cpu->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
            return (($selector << 4) & 0xFFFFF);
        }

        $selector = $runtime->memoryAccessor()->fetch($segment)->asByte() & 0xFFFF;
        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        // Match current emulator behavior: if the descriptor can't be resolved, treat base as 0.
        // (SegmentTrait::segmentBase() returns 0 for missing/not-present descriptors.)
        if ($descriptor === null || !($descriptor['present'] ?? false)) {
            return 0;
        }
        return (int) ($descriptor['base'] ?? 0);
    }

    /**
     * Minimal segment descriptor reader (GDT/LDT) for protected mode.
     *
     * @return array{base:int,limit:int,present:bool}|null
     */
    private function readSegmentDescriptor(RuntimeInterface $runtime, int $selector): ?array
    {
        $cpu = $runtime->context()->cpu();
        if (!$cpu->isProtectedMode()) {
            return null;
        }

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

    private function updateAddFlags32($ma, int $dest, int $src, int $result): void
    {
        $res = $result & 0xFFFFFFFF;
        $ma->setZeroFlag($res === 0);
        $ma->setSignFlag(($res & 0x80000000) !== 0);
        $ma->setParityFlag($this->calculateParity($res & 0xFF));

        $ma->setAuxiliaryCarryFlag((($dest & 0x0F) + ($src & 0x0F)) > 0x0F);

        $signA = ($dest >> 31) & 1;
        $signB = ($src >> 31) & 1;
        $signR = ($res >> 31) & 1;
        $ma->setOverflowFlag(($signA === $signB) && ($signA !== $signR));

        $ma->setCarryFlag((($dest + $src) & 0x1_0000_0000) !== 0);
    }

    private function updateSubFlags32($ma, int $dest, int $src, int $result): void
    {
        $res = $result & 0xFFFFFFFF;
        $ma->setZeroFlag($res === 0);
        $ma->setSignFlag(($res & 0x80000000) !== 0);
        $ma->setParityFlag($this->calculateParity($res & 0xFF));

        $ma->setAuxiliaryCarryFlag(($dest & 0x0F) < ($src & 0x0F));

        $signA = ($dest >> 31) & 1;
        $signB = ($src >> 31) & 1;
        $signR = ($res >> 31) & 1;
        $ma->setOverflowFlag(($signA !== $signB) && ($signA !== $signR));

        // CF (borrow) for SUB is set when dest < src (unsigned).
        $ma->setCarryFlag(($dest & 0xFFFFFFFF) < ($src & 0xFFFFFFFF));
    }
}
