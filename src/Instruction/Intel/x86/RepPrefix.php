<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\PagedMemoryStream;
use PHPMachineEmulator\Util\UInt64;

class RepPrefix implements InstructionInterface
{
    use Instructable;

    /**
     * VGA legacy video memory range (text/graphics).
     *
     * Writes to this range must go through MemoryAccessor to trigger observers
     * (e.g., text mode 0xB8000) and other MMIO-like behavior.
     */
    private const VIDEO_MEM_MIN = 0x000A0000;
    private const VIDEO_MEM_MAX_EXCLUSIVE = 0x000C0000;

    /**
     * String instructions that don't check ZF (MOVS, STOS, LODS, INS, OUTS).
     * These instructions continue iteration as long as ECX > 0.
     */
    private const NO_ZF_CHECK_INSTRUCTIONS = [
        Movsb::class,
        Movsw::class,
        Stosb::class,
        Stosw::class,
        Lodsb::class,
        Lodsw::class,
        Ins::class,
        Outs::class,
    ];

    private ?bool $traceGrubCfgCopy = null;
    private bool $tracedGrubCfgCopy = false;

    private static function rangesOverlap(int $minA, int $maxA, int $minB, int $maxB): bool
    {
        return $minA <= $maxB && $maxA >= $minB;
    }

    public function opcodes(): array
    {
        // REP/REPNZ can appear after other legacy prefixes; accept them in any order.
        return $this->applyPrefixes([0xF3, 0xF2]);
    }

    private function shouldTraceGrubCfgCopy(RuntimeInterface $runtime): bool
    {
        if ($this->traceGrubCfgCopy !== null) {
            return $this->traceGrubCfgCopy;
        }
        $this->traceGrubCfgCopy = $runtime->logicBoard()->debug()->trace()->traceGrubCfgCopy;
        return $this->traceGrubCfgCopy;
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        // Apply any legacy prefixes that were placed before REP/REPNZ in the opcode stream.
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $cpu = $runtime->context()->cpu();
        $iterationContext = $cpu->iteration();

        // Set up iteration handler: handles ECX decrement and loop control
        $iterationContext->setIterate(function (RuntimeInterface $runtime, InstructionExecutorInterface $executor) use ($opcodes): ExecutionStatus {
            $lastResult = ExecutionStatus::SUCCESS;
            // This will be set to the string instruction's IP after first execute
            $stringInstructionIp = $runtime->memory()->offset();
            $firstIteration = true;

            // Check ECX before starting - if zero, do nothing
            $counter = $this->readIndex($runtime, RegisterType::ECX);
            if ($counter <= 0) {
                $this->consumeSkippedStringInstructionIfCountZero($runtime);
                return ExecutionStatus::SUCCESS;
            }

            // Execute first iteration to identify the instruction
            $counter--;
            $this->writeIndex($runtime, RegisterType::ECX, $counter);
            $ipBeforeExecute = $runtime->memory()->offset();

            // Debug: log SI/DI before first iteration
            $siBefore = $this->readIndex($runtime, RegisterType::ESI);
            $diBefore = $this->readIndex($runtime, RegisterType::EDI);

            $result = $executor->execute($runtime);
            $lastResult = $result;

            // Debug: log SI/DI after first iteration
            $siAfter = $this->readIndex($runtime, RegisterType::ESI);
            $diAfter = $this->readIndex($runtime, RegisterType::EDI);
            if ($siBefore !== $siAfter || $diBefore !== $diAfter) {
                $runtime->option()->logger()->debug(sprintf(
                    'REP FIRST: SI 0x%04X->0x%04X DI 0x%04X->0x%04X ECX=%d',
                    $siBefore, $siAfter, $diBefore, $diAfter, $counter
                ));
            }

            // If result is CONTINUE (prefix), restore ECX and return to process more prefixes
            if ($result === ExecutionStatus::CONTINUE) {
                $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                return ExecutionStatus::CONTINUE;
            }

            $lastInstruction = $executor->lastInstruction();

            // REP/REPE/REPNE repetition only applies to string instructions.
            // For everything else, 0xF2/0xF3 acts as a hint/mandatory prefix and must not
            // modify the count register or loop.
            if ($lastInstruction !== null && !$this->isStringInstruction($lastInstruction)) {
                $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                return $result;
            }

            if ($result !== ExecutionStatus::SUCCESS) {
                return $result;
            }

            $stringInstructionIp = $ipBeforeExecute;

            // For CMPS/SCAS with REPE/REPNE, termination condition must be checked
            // after the first iteration as well. If the condition already failed,
            // stop immediately without executing further iterations.
            $opcode = $opcodes[0];
            if ($lastInstruction instanceof Cmpsb ||
                $lastInstruction instanceof Cmpsw ||
                $lastInstruction instanceof Scasb ||
                $lastInstruction instanceof Scasw) {
                $zf = $runtime->memoryAccessor()->shouldZeroFlag();
                if (($opcode === 0xF2 && $zf) || ($opcode === 0xF3 && !$zf)) {
                    return $lastResult;
                }
            }

            // Check if this is a bulk-optimizable instruction (STOS, MOVS without ZF check)
            $isBulkOptimizable = false;
            if ($lastInstruction !== null) {
                foreach (self::NO_ZF_CHECK_INSTRUCTIONS as $noZfClass) {
                    if ($lastInstruction instanceof $noZfClass) {
                        $isBulkOptimizable = true;
                        break;
                    }
                }
            }
            $lastOpcodes = $executor->lastOpcodes();
            if ($isBulkOptimizable && $lastOpcodes !== null && $this->hasLegacyPrefix($lastOpcodes)) {
                $isBulkOptimizable = false;
            }

            // Bulk optimization - only for instructions that do not depend on ZF (MOVS/STOS/LODS/INS/OUTS).
            // CMPS/SCAS are handled in the standard per-iteration loop to preserve exact REP semantics.
            if ($counter > 0 && $lastInstruction !== null && $isBulkOptimizable) {
                $bulkResult = $this->tryBulkExecute($runtime, $lastInstruction, $counter, $opcode);
                if ($bulkResult !== null) {
                    return $bulkResult;
                }
            }

            // Fallback: per-iteration execution for bulk-optimizable instructions
            if ($isBulkOptimizable && $counter > 0) {
                while ($counter > 0) {
                    $counter--;
                    $this->writeIndex($runtime, RegisterType::ECX, $counter);

                    // Execute without going through the full executor (which logs)
                    $result = $lastInstruction->process($runtime, $executor->lastOpcodes());
                    $lastResult = $result;

                    // The executor normally clears transient prefix state after each instruction.
                    // When we bypass it for speed, we must do the same to avoid prefix bleed.
                    if ($result !== ExecutionStatus::CONTINUE) {
                        $runtime->context()->cpu()->clearTransientOverrides();
                    }

                    if ($result !== ExecutionStatus::SUCCESS) {
                        return $result;
                    }
                }

                return $lastResult;
            }

            // Standard loop for instructions that need ZF checking (CMPS, SCAS)
            while ($counter > 0) {
                $counter--;
                $this->writeIndex($runtime, RegisterType::ECX, $counter);

                // Reset IP to string instruction start for next iteration
                $runtime->memory()->setOffset($stringInstructionIp);

                // Execute the instruction
                $result = $executor->execute($runtime);
                $lastResult = $result;

                if ($result !== ExecutionStatus::SUCCESS) {
                    return $result;
                }

                if ($counter <= 0) {
                    break;
                }

                // REPNE/REPE termination for CMPS/SCAS based on ZF
                $zf = $runtime->memoryAccessor()->shouldZeroFlag();
                if ($opcode === 0xF2 && $zf) { // REPNZ/REPNE: stop when ZF=1
                    break;
                }
                if ($opcode === 0xF3 && !$zf) { // REPE/REPZ: stop when ZF=0
                    break;
                }
            }

            return $lastResult;
        });

        return ExecutionStatus::CONTINUE;
    }

    /**
     * Detect legacy prefixes (operand/address/segment/lock) in the opcode stream.
     */
    private function hasLegacyPrefix(array $opcodes): bool
    {
        foreach ($opcodes as $byte) {
            $b = $byte & 0xFF;
            if (in_array($b, [0x66, 0x67, 0xF0, 0x26, 0x2E, 0x36, 0x3E, 0x64, 0x65], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * When REP/REPE/REPNE is used with a zero count, x86 consumes the following instruction
     * bytes but does not execute the string operation. Without this, the next instruction
     * would execute once without REP, which breaks semantics (e.g. REP MOVSB with ECX=0).
     *
     * For non-string instructions (e.g. F3 90 / PAUSE), REP is treated as a hint and the
     * following instruction should execute normally; in that case we rewind.
     */
    private function consumeSkippedStringInstructionIfCountZero(RuntimeInterface $runtime): void
    {
        $memory = $runtime->memory();
        $start = $memory->offset();

        $cpu = $runtime->context()->cpu();

        $opcode = null;
        $max = 15; // architectural maximum instruction length
        for ($i = 0; $i < $max && !$memory->isEOF(); $i++) {
            $b = $memory->byte() & 0xFF;

            // Treat redundant prefixes as part of the same instruction.
            if ($b === 0xF2 || $b === 0xF3) {
                continue;
            }

            // Legacy prefixes (operand/address size, lock, segment overrides).
            if (in_array($b, [0x66, 0x67, 0xF0, 0x26, 0x2E, 0x36, 0x3E, 0x64, 0x65], true)) {
                continue;
            }

            // REX prefix (64-bit mode only).
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $b >= 0x40 && $b <= 0x4F) {
                continue;
            }

            $opcode = $b;
            break;
        }

        if ($opcode === null) {
            return;
        }

        // REP is only semantically relevant for these string instructions; otherwise it acts as a hint.
        $isString = in_array($opcode, [
            0xA4, 0xA5, // MOVS
            0xA6, 0xA7, // CMPS
            0xAA, 0xAB, // STOS
            0xAC, 0xAD, // LODS
            0xAE, 0xAF, // SCAS
            0x6C, 0x6D, // INS
            0x6E, 0x6F, // OUTS
        ], true);

        if (!$isString) {
            // Rewind so the following instruction executes normally.
            $memory->setOffset($start);
        }
    }

    private function isStringInstruction(InstructionInterface $instruction): bool
    {
        return $instruction instanceof Movsb
            || $instruction instanceof Movsw
            || $instruction instanceof Stosb
            || $instruction instanceof Stosw
            || $instruction instanceof Lodsb
            || $instruction instanceof Lodsw
            || $instruction instanceof Ins
            || $instruction instanceof Outs
            || $instruction instanceof Cmpsb
            || $instruction instanceof Cmpsw
            || $instruction instanceof Scasb
            || $instruction instanceof Scasw;
    }

    /**
     * Try to execute bulk operation for string instructions.
     * Returns ExecutionStatus if bulk execution was performed, null otherwise.
     */
    private const PAGE_SIZE = 0x1000; // 4KB

    private function tryBulkExecute(RuntimeInterface $runtime, InstructionInterface $instruction, int $count, int $opcode): ?ExecutionStatus
    {
        // Enable all bulk optimizations
        return match (true) {
            $instruction instanceof Movsb => $this->bulkMovsb($runtime, $count),
            $instruction instanceof Movsw => $this->bulkMovsw($runtime, $count),
            $instruction instanceof Stosb => $this->bulkStosb($runtime, $count),
            $instruction instanceof Stosw => $this->bulkStosw($runtime, $count),
            $instruction instanceof Cmpsb => $this->bulkCmpsb($runtime, $count, $opcode),
            $instruction instanceof Cmpsw => $this->bulkCmpsw($runtime, $count, $opcode),
            $instruction instanceof Scasb => $this->bulkScasb($runtime, $count, $opcode),
            $instruction instanceof Scasw => $this->bulkScasw($runtime, $count, $opcode),

            default => null,
        };
    }

    /**
     * Calculate how many bytes can be processed without crossing a page boundary.
     */
    private function bytesUntilPageBoundary(int $address, int $totalBytes): int
    {
        $pageOffset = $address & (self::PAGE_SIZE - 1);
        $bytesInPage = self::PAGE_SIZE - $pageOffset;
        return min($bytesInPage, $totalBytes);
    }

    /**
     * Check if the entire range fits within a single page.
     */
    private function fitsInSinglePage(int $address, int $byteCount): bool
    {
        return $this->bytesUntilPageBoundary($address, $byteCount) >= $byteCount;
    }

    /**
     * REP MOVSB - bulk memory copy (byte)
     * Uses same read/write methods as Movsb.php for correctness
     */
    private function bulkMovsb(RuntimeInterface $runtime, int $count): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);
        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Calculate segment:offset addresses (not linear yet)
        $srcSegOff = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $checkAfterCopy = false;

        // Debug: Log any REP MOVSB that writes to 0x0700 area
        if ($dstSegOff >= 0x0700 && $dstSegOff < 0x0710) {
            $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
            $es = $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte();
            $ip = $runtime->memory()->offset();
            $runtime->option()->logger()->warning(sprintf(
                'REP MOVSB to 0x0700: IP=0x%05X DS=0x%04X ES=0x%04X SI=0x%04X DI=0x%04X src=0x%05X dst=0x%05X count=%d',
                $ip, $ds, $es, $si, $di, $srcSegOff, $dstSegOff, $count
            ));
            // Debug: check source data
            $srcData = [];
            for ($j = 0; $j < 8; $j++) {
                $srcData[] = sprintf('%02X', $this->readMemory8($runtime, $srcSegOff + $j));
            }
            $runtime->option()->logger()->warning(sprintf(
                'REP MOVSB source data: %s',
                implode(' ', $srcData)
            ));
        }
        // Debug: Log relocation copy (0x0700 -> 0x9F840)
        if ($srcSegOff >= 0x0700 && $srcSegOff < 0x0D00 && $dstSegOff >= 0x9F000) {
            $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
            $es = $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte();
            $runtime->option()->logger()->warning(sprintf(
                'REP MOVSB RELOC: DS=0x%04X ES=0x%04X SI=0x%04X DI=0x%04X src=0x%05X dst=0x%05X count=%d',
                $ds, $es, $si, $di, $srcSegOff, $dstSegOff, $count
            ));
            // Check value at absolute 0x0837 (where MOV [CS:0x137] wrote 0x01)
            $srcVal = $this->readMemory8($runtime, 0x0837);
            $runtime->option()->logger()->warning(sprintf(
                'REP MOVSB RELOC: mem[0x0837]=0x%02X (expected 0x01)',
                $srcVal
            ));
            // Also check destination 0x9F977 before copy
            $dstVal = $this->readMemory8($runtime, 0x9F977);
            $runtime->option()->logger()->warning(sprintf(
                'REP MOVSB RELOC: mem[0x9F977]=0x%02X before copy',
                $dstVal
            ));
            // Set flag to check after copy
            $checkAfterCopy = true;
        }

        // Fast path: use MemoryStream internal copy when the address range is a contiguous plain RAM span.
        // This avoids per-byte PHP overhead for large copies (e.g., GRUB module/font relocation).
        $usedFastCopy = false;
        if ($count > 0) {
            $cpu = $runtime->context()->cpu();
            if (!$cpu->isPagingEnabled()) {
                if ($this->canFastBulkMovs($runtime, $si, $di, $srcSegOff, $dstSegOff, $count, 1, $step)) {
                    [$srcMin, , $byteCount] = $this->bulkMovsRange($srcSegOff, $count, 1, $step);
                    [$dstMin] = $this->bulkMovsRange($dstSegOff, $count, 1, $step);
                    $runtime->memory()->copy($runtime->memory(), $srcMin, $dstMin, $byteCount);
                    $usedFastCopy = true;
                }
                // Special-case: overlapping forward copies (dst>src) are common in LZ-style decoders.
                // REP MOVSB semantics in this case are NOT memmove; the forward, byte-by-byte behavior
                // causes the copied pattern to repeat when length > distance. Emulate this efficiently
                // using an initial non-overlapping seed copy followed by exponential self-copy.
                if (!$usedFastCopy && $step > 0 && $cpu->isA20Enabled()) {
                    [$srcMin, $srcMax, $byteCount] = $this->bulkMovsRange($srcSegOff, $count, 1, $step);
                    [$dstMin, $dstMax] = $this->bulkMovsRange($dstSegOff, $count, 1, $step);

                    $videoMax = self::VIDEO_MEM_MAX_EXCLUSIVE - 1;
                    if ($srcMin >= 0 && $dstMin >= 0 && $srcMax < 0xE0000000 && $dstMax < 0xE0000000 &&
                        !self::rangesOverlap($srcMin, $srcMax, self::VIDEO_MEM_MIN, $videoMax) &&
                        !self::rangesOverlap($dstMin, $dstMax, self::VIDEO_MEM_MIN, $videoMax)
                    ) {
                        $distance = $dstMin - $srcMin;
                        $overlapForward = $distance > 0 && $distance < $byteCount && $dstMin <= $srcMax;

                        if ($overlapForward) {
                            // Preserve 16-bit index wrapping semantics: only optimize when indices won't wrap.
                            $addrSize = $cpu->addressSize();
                            if ($addrSize !== 16 || (($si + ($count - 1)) <= 0xFFFF && ($di + ($count - 1)) <= 0xFFFF)) {
                                // Ensure ranges exist (reads are zero-filled for unallocated memory).
                                if ($runtime->memory()->ensureCapacity($srcMin + $byteCount) &&
                                    $runtime->memory()->ensureCapacity($dstMin + $byteCount)) {
                                    // Seed the first "distance" bytes (src..src+distance-1 -> dst..dst+distance-1).
                                    $runtime->memory()->copy($runtime->memory(), $srcMin, $dstMin, $distance);

                                    // Fill the remainder by repeatedly copying what we already wrote.
                                    $filled = $distance;
                                    while ($filled < $byteCount) {
                                        $copySize = min($filled, $byteCount - $filled);
                                        $runtime->memory()->copy($runtime->memory(), $dstMin, $dstMin + $filled, $copySize);
                                        $filled += $copySize;
                                    }

                                    $usedFastCopy = true;
                                }
                            }
                        }
                    }
                }
            } else {
                $plan = $this->planFastBulkMovs($runtime, $si, $di, $srcSegOff, $dstSegOff, $count, 1, $step);
                if ($plan !== null) {
                    $usedFastCopy = $this->copyLinearRangeWithPaging(
                        $runtime,
                        $plan['srcMin'],
                        $plan['dstMin'],
                        $plan['byteCount'],
                        $plan['copyBackward'],
                    );
                }
            }
        }

        if (!$usedFastCopy) {
            // Process each byte using the same methods as Movsb.php
            for ($i = 0; $i < $count; $i++) {
                // Read using readMemory8 (same as Movsb.php line 27-30)
                $srcAddr = $srcSegOff + ($step * $i);
                $value = $this->readMemory8($runtime, $srcAddr);

                // Write using writeRawByte after allocate (same as Movsb.php line 32-34)
                $dstAddr = $dstSegOff + ($step * $i);
                if ($dstAddr >= 0xE0000000 && $dstAddr < 0xE1000000) {
                    $this->writeMemory8($runtime, $dstAddr, $value);
                } else {
                    $destAddress = $this->translateLinearWithMmio($runtime, $dstAddr, true);
                    $ma->allocate($destAddress, safe: false);
                    $ma->writeRawByte($destAddress, $value);
                }

                // Debug: track writes to 0x0700-0x0710
                if ($dstAddr >= 0x0700 && $dstAddr <= 0x0710) {
                    $runtime->option()->logger()->warning(sprintf(
                        'REP MOVSB WRITE: 0x%05X <- 0x%02X (from 0x%05X)',
                        $dstAddr, $value, $srcAddr
                    ));
                }

                // Debug: track writes to 0x0CA0-0x0CB0 area (source data for setup code)
                if ($dstAddr >= 0x0CA0 && $dstAddr <= 0x0CB0) {
                    $runtime->option()->logger()->warning(sprintf(
                        'REP MOVSB WRITE to source area: 0x%05X <- 0x%02X',
                        $dstAddr, $value
                    ));
                }
            }
        }

        if (!$this->tracedGrubCfgCopy && $this->shouldTraceGrubCfgCopy($runtime)) {
            $fullCount = $count + 1;
            $firstSrc = ($srcSegOff - $step) & 0xFFFFFFFF;
            $firstDst = ($dstSegOff - $step) & 0xFFFFFFFF;

            if ($step > 0) {
                $srcMin = $firstSrc;
                $srcMax = ($firstSrc + ($fullCount - 1)) & 0xFFFFFFFF;
                $dstMin = $firstDst;
                $dstMax = ($firstDst + ($fullCount - 1)) & 0xFFFFFFFF;
            } else {
                $srcMax = $firstSrc;
                $srcMin = ($firstSrc - ($fullCount - 1)) & 0xFFFFFFFF;
                $dstMax = $firstDst;
                $dstMin = ($firstDst - ($fullCount - 1)) & 0xFFFFFFFF;
            }

            $grubCfgMin = 0x0006A800;
            $grubCfgMax = 0x0006AFFF;
            if ($srcMax >= $grubCfgMin && $srcMin <= $grubCfgMax && $fullCount <= 0x4000) {
                $bytes = '';
                for ($i = 0; $i < $fullCount; $i++) {
                    $bytes .= chr($this->readMemory8($runtime, ($dstMin + $i) & 0xFFFFFFFF));
                }
                $sha1 = sha1($bytes);
                $ascii = preg_replace('/[^\\x20-\\x7E]/', '.', substr($bytes, 0, min(256, strlen($bytes)))) ?? '';
                $path = sprintf('debug/grubcfg_copy_%08X_%d.bin', $dstMin, $fullCount);
                @file_put_contents($path, $bytes);

                $runtime->option()->logger()->warning(sprintf(
                    'GRUBCFG COPY (MOVSB): src=0x%08X..0x%08X dst=0x%08X..0x%08X len=%d sha1=%s saved=%s ascii="%s"',
                    $srcMin,
                    $srcMax,
                    $dstMin,
                    $dstMax,
                    $fullCount,
                    $sha1,
                    $path,
                    $ascii,
                ));

                $this->tracedGrubCfgCopy = true;
            }
        }

        // Update registers
        $totalStep = $step * $count;
        $this->writeIndex($runtime, RegisterType::ESI, $si + $totalStep);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

        // If this copy overwrote a previously executed page, invalidate instruction caches.
        // GRUB loads/relocates modules via REP MOVS and may reuse memory regions.
        // Note: bulkMovs handles the remaining iterations; include the already-executed first iteration too.
        $totalCount = $count + 1;
        $destStart = $step > 0 ? ($dstSegOff - 1) : ($dstSegOff - ($count - 1));
        $runtime->architectureProvider()->instructionExecutor()->invalidateCachesIfExecutedPageOverlaps(
            $destStart,
            $totalCount,
        );

        // Check after copy
        if ($checkAfterCopy) {
            $dstValAfter = $this->readMemory8($runtime, 0x9F977);
            $runtime->option()->logger()->warning(sprintf(
                'REP MOVSB RELOC: mem[0x9F977]=0x%02X after copy',
                $dstValAfter
            ));
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * REP MOVSW/MOVSD - bulk memory copy (word/dword)
     * Uses same read/write methods as Movsw.php for correctness
     */
    private function bulkMovsw(RuntimeInterface $runtime, int $count): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $opSize = $runtime->context()->cpu()->operandSize();
        $width = match ($opSize) {
            16 => 2,
            32 => 4,
            64 => 8,
            default => 2,
        };
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);
        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Calculate segment:offset addresses (not linear yet)
        $srcSegOff = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $usedFastCopy = false;
        if ($count > 0) {
            $cpu = $runtime->context()->cpu();
            if (!$cpu->isPagingEnabled()) {
                if ($this->canFastBulkMovs($runtime, $si, $di, $srcSegOff, $dstSegOff, $count, $width, $step)) {
                    [$srcMin, , $byteCount] = $this->bulkMovsRange($srcSegOff, $count, $width, $step);
                    [$dstMin] = $this->bulkMovsRange($dstSegOff, $count, $width, $step);
                    $runtime->memory()->copy($runtime->memory(), $srcMin, $dstMin, $byteCount);
                    $usedFastCopy = true;
                }
            } else {
                $plan = $this->planFastBulkMovs($runtime, $si, $di, $srcSegOff, $dstSegOff, $count, $width, $step);
                if ($plan !== null) {
                    $usedFastCopy = $this->copyLinearRangeWithPaging(
                        $runtime,
                        $plan['srcMin'],
                        $plan['dstMin'],
                        $plan['byteCount'],
                        $plan['copyBackward'],
                    );
                }
            }
        }

        if (!$usedFastCopy) {
            // Process each element using the same methods as Movsw.php
            for ($i = 0; $i < $count; $i++) {
                $offset = $step * $width * $i;

                $value = match ($opSize) {
                    16 => $this->readMemory16($runtime, $srcSegOff + $offset),
                    32 => $this->readMemory32($runtime, $srcSegOff + $offset),
                    64 => $this->readMemory64($runtime, $srcSegOff + $offset),
                    default => $this->readMemory16($runtime, $srcSegOff + $offset),
                };

                // Write using writeBySize after allocate (same as Movsw.php line 34-36)
                $dstAddr = $dstSegOff + $offset;
                if ($dstAddr >= 0xE0000000 && $dstAddr < 0xE1000000) {
                    match ($opSize) {
                        16 => $this->writeMemory16($runtime, $dstAddr, $value instanceof UInt64 ? $value->toInt() : $value),
                        32 => $this->writeMemory32($runtime, $dstAddr, $value instanceof UInt64 ? $value->toInt() : $value),
                        64 => $this->writeMemory64($runtime, $dstAddr, $value),
                        default => $this->writeMemory16($runtime, $dstAddr, $value instanceof UInt64 ? $value->toInt() : $value),
                    };
                } else {
                    $destAddress = $this->translateLinearWithMmio($runtime, $dstAddr, true);
                    $ma->allocate($destAddress, $width, safe: false);
                    $ma->writeBySize($destAddress, $value instanceof UInt64 ? $value->toInt() : $value, $opSize);
                }
            }
        }

        if (!$this->tracedGrubCfgCopy && $this->shouldTraceGrubCfgCopy($runtime)) {
            $fullElems = $count + 1;
            $fullBytes = $fullElems * $width;
            $firstSrc = ($srcSegOff - ($step * $width)) & 0xFFFFFFFF;
            $firstDst = ($dstSegOff - ($step * $width)) & 0xFFFFFFFF;

            if ($step > 0) {
                $srcMin = $firstSrc;
                $srcMax = ($firstSrc + ($fullBytes - 1)) & 0xFFFFFFFF;
                $dstMin = $firstDst;
                $dstMax = ($firstDst + ($fullBytes - 1)) & 0xFFFFFFFF;
            } else {
                $srcMax = $firstSrc;
                $srcMin = ($firstSrc - ($fullBytes - 1)) & 0xFFFFFFFF;
                $dstMax = $firstDst;
                $dstMin = ($firstDst - ($fullBytes - 1)) & 0xFFFFFFFF;
            }

            $grubCfgMin = 0x0006A800;
            $grubCfgMax = 0x0006AFFF;
            if ($srcMax >= $grubCfgMin && $srcMin <= $grubCfgMax && $fullBytes <= 0x4000) {
                $bytes = '';
                for ($i = 0; $i < $fullBytes; $i++) {
                    $bytes .= chr($this->readMemory8($runtime, ($dstMin + $i) & 0xFFFFFFFF));
                }
                $sha1 = sha1($bytes);
                $ascii = preg_replace('/[^\\x20-\\x7E]/', '.', substr($bytes, 0, min(256, strlen($bytes)))) ?? '';
                $path = sprintf('debug/grubcfg_copy_%08X_%d.bin', $dstMin, $fullBytes);
                @file_put_contents($path, $bytes);

                $runtime->option()->logger()->warning(sprintf(
                    'GRUBCFG COPY (MOVS width=%d): src=0x%08X..0x%08X dst=0x%08X..0x%08X len=%d sha1=%s saved=%s ascii="%s"',
                    $width,
                    $srcMin,
                    $srcMax,
                    $dstMin,
                    $dstMax,
                    $fullBytes,
                    $sha1,
                    $path,
                    $ascii,
                ));

                $this->tracedGrubCfgCopy = true;
            }
        }

        // Update registers
        $totalStep = $step * $width * $count;
        $this->writeIndex($runtime, RegisterType::ESI, $si + $totalStep);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

        // If this copy overwrote a previously executed page, invalidate instruction caches.
        // Note: bulkMovs handles the remaining iterations; include the already-executed first iteration too.
        $byteCount = ($count + 1) * $width;
        $destStart = $step > 0 ? ($dstSegOff - $width) : ($dstSegOff - (($count - 1) * $width));
        $runtime->architectureProvider()->instructionExecutor()->invalidateCachesIfExecutedPageOverlaps(
            $destStart,
            $byteCount,
        );

        return ExecutionStatus::SUCCESS;
    }

    /**
     * REP STOSB - bulk memory fill (byte)
     * Uses same write method as Stosb.php for correctness
     */
    private function bulkStosb(RuntimeInterface $runtime, int $count): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $byte = $ma->fetch(RegisterType::EAX)->asLowBit();
        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Calculate segment:offset address (not linear yet)
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $usedFastFill = false;
        if ($count > 0 && $this->canFastBulkStos($runtime, $di, $dstSegOff, $count, 1, $step)) {
            [$dstMin, $byteCount] = $this->bulkStosRange($dstSegOff, $count, 1, $step);

            // Seed the first byte then exponentially copy it to fill the rest.
            $ma->writeRawByte($dstMin, $byte);
            $filled = 1;
            while ($filled < $byteCount) {
                $copySize = min($filled, $byteCount - $filled);
                $runtime->memory()->copy($runtime->memory(), $dstMin, $dstMin + $filled, $copySize);
                $filled += $copySize;
            }
            $usedFastFill = true;
        }

        if (!$usedFastFill) {
            // Process each byte using the same methods as Stosb.php
            for ($i = 0; $i < $count; $i++) {
                // Write using writeRawByte after allocate (same as Stosb.php line 33-39)
                $dstAddr = $dstSegOff + ($step * $i);
                if ($dstAddr >= 0xE0000000 && $dstAddr < 0xE1000000) {
                    $this->writeMemory8($runtime, $dstAddr, $byte);
                } else {
                    $address = $this->translateLinearWithMmio($runtime, $dstAddr, true);
                    $ma->allocate($address, safe: false);
                    $ma->writeRawByte($address, $byte);
                }
            }
        }

        // Update registers
        $totalStep = $step * $count;
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * REP STOSW/STOSD - bulk memory fill (word/dword)
     * Uses same write method as Stosw.php for correctness
     */
    private function bulkStosw(RuntimeInterface $runtime, int $count): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $opSize = $runtime->context()->cpu()->operandSize();
        $width = match ($opSize) {
            16 => 2,
            32 => 4,
            64 => 8,
            default => 2,
        };
        $value = $ma->fetch(RegisterType::EAX)->asBytesBySize($opSize);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Calculate segment:offset address (not linear yet)
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        $usedFastFill = false;
        if ($count > 0 && $this->canFastBulkStos($runtime, $di, $dstSegOff, $count, $width, $step)) {
            [$dstMin, $byteCount] = $this->bulkStosRange($dstSegOff, $count, $width, $step);

            // Seed one element then exponentially copy it to fill the rest.
            match ($opSize) {
                16 => $this->writeMemory16($runtime, $dstMin, $value),
                32 => $this->writeMemory32($runtime, $dstMin, $value),
                64 => $this->writeMemory64($runtime, $dstMin, $value),
                default => $this->writeMemory16($runtime, $dstMin, $value),
            };

            $filled = $width;
            while ($filled < $byteCount) {
                $copySize = min($filled, $byteCount - $filled);
                $runtime->memory()->copy($runtime->memory(), $dstMin, $dstMin + $filled, $copySize);
                $filled += $copySize;
            }
            $usedFastFill = true;
        }

        if (!$usedFastFill) {
            // Process each element using the same methods as Stosw.php
            for ($i = 0; $i < $count; $i++) {
                $offset = $step * $width * $i;

                // Write using writeBySize after allocate (same as Stosw.php line 30-31)
                $dstAddr = $dstSegOff + $offset;
                if ($dstAddr >= 0xE0000000 && $dstAddr < 0xE1000000) {
                    match ($opSize) {
                        16 => $this->writeMemory16($runtime, $dstAddr, $value),
                        32 => $this->writeMemory32($runtime, $dstAddr, $value),
                        64 => $this->writeMemory64($runtime, $dstAddr, $value),
                        default => $this->writeMemory16($runtime, $dstAddr, $value),
                    };
                } else {
                    $address = $this->translateLinearWithMmio($runtime, $dstAddr, true);
                    $ma->allocate($address, safe: false);
                    $ma->writeBySize($address, $value, $opSize);
                }
            }
        }

        // Update registers
        $totalStep = $step * $width * $count;
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Decide whether we can use MemoryStream::copy for REP MOVS without breaking wrapping/MMIO semantics.
     *
     * This fast path is only enabled when:
     * - Paging is disabled (linear==physical)
     * - The address-size indices do not wrap during the operation
     * - The ranges stay below common MMIO regions (e.g., LFB)
     * - The overlap direction does not require strict per-iteration semantics
     */
    private function canFastBulkMovs(
        RuntimeInterface $runtime,
        int $si,
        int $di,
        int $srcLinear,
        int $dstLinear,
        int $count,
        int $width,
        int $step,
    ): bool {
        if ($count <= 0) {
            return false;
        }

        $cpu = $runtime->context()->cpu();
        if ($cpu->isPagingEnabled()) {
            return false;
        }

        // Avoid A20/segment wrapping edge cases in legacy modes.
        if (!$cpu->isLongMode() && !$cpu->isA20Enabled()) {
            return false;
        }

        // Avoid 16-bit index wrapping (common in early real mode) unless the range is provably in-bounds.
        $addrSize = $cpu->addressSize();
        if ($addrSize === 16) {
            $delta = ($count - 1) * $width;
            if ($step > 0) {
                if ($si + $delta > 0xFFFF || $di + $delta > 0xFFFF) {
                    return false;
                }
            } else {
                if ($si - $delta < 0 || $di - $delta < 0) {
                    return false;
                }
            }
        }

        [$srcMin, $srcMax, $byteCount] = $this->bulkMovsRange($srcLinear, $count, $width, $step);
        [$dstMin, $dstMax] = $this->bulkMovsRange($dstLinear, $count, $width, $step);

        // Avoid MMIO-like ranges that rely on observers (e.g., VGA text/graphics memory).
        $videoMax = self::VIDEO_MEM_MAX_EXCLUSIVE - 1;
        if (self::rangesOverlap($srcMin, $srcMax, self::VIDEO_MEM_MIN, $videoMax) ||
            self::rangesOverlap($dstMin, $dstMax, self::VIDEO_MEM_MIN, $videoMax)
        ) {
            return false;
        }

        // Stay away from common MMIO ranges (VBE LFB, APIC, etc.).
        if ($srcMin < 0 || $dstMin < 0) {
            return false;
        }
        if ($srcMax >= 0xE0000000 || $dstMax >= 0xE0000000) {
            return false;
        }

        // Avoid overlap cases where REP MOVS semantics differ from memmove.
        if ($step > 0) {
            if ($dstMin > $srcMin && $dstMin <= $srcMax) {
                return false;
            }
        } else {
            if ($dstMax < $srcMax && $dstMax >= $srcMin) {
                return false;
            }
        }

        // Ensure both ranges exist. The emulator's memory model expands reads with zero-fill,
        // so the fast path must also extend the source region to preserve semantics.
        if (!$runtime->memory()->ensureCapacity($srcMin + $byteCount)) {
            return false;
        }
        if (!$runtime->memory()->ensureCapacity($dstMin + $byteCount)) {
            return false;
        }

        return true;
    }

    /**
     * Compute a safe bulk MOVS plan for a memmove-style copy.
     *
     * This deliberately does NOT require paging to be disabled; callers must choose
     * the appropriate copy implementation (linear==physical vs translateLinear chunks).
     *
     * @return array{srcMin:int,srcMax:int,dstMin:int,dstMax:int,byteCount:int,copyBackward:bool}|null
     */
    private function planFastBulkMovs(
        RuntimeInterface $runtime,
        int $si,
        int $di,
        int $srcLinear,
        int $dstLinear,
        int $count,
        int $width,
        int $step,
    ): ?array {
        if ($count <= 0) {
            return null;
        }

        $cpu = $runtime->context()->cpu();

        // Only handle legacy/compat addresses for now.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            return null;
        }

        // Avoid A20/segment wrapping edge cases in legacy modes.
        if (!$cpu->isLongMode() && !$cpu->isA20Enabled()) {
            return null;
        }

        // Avoid 16-bit index wrapping unless provably in-bounds.
        $addrSize = $cpu->addressSize();
        if ($addrSize === 16) {
            $delta = ($count - 1) * $width;
            if ($step > 0) {
                if ($si + $delta > 0xFFFF || $di + $delta > 0xFFFF) {
                    return null;
                }
            } else {
                if ($si - $delta < 0 || $di - $delta < 0) {
                    return null;
                }
            }
        }

        [$srcMin, $srcMax, $byteCount] = $this->bulkMovsRange($srcLinear, $count, $width, $step);
        [$dstMin, $dstMax] = $this->bulkMovsRange($dstLinear, $count, $width, $step);

        // Avoid MMIO-like ranges that rely on observers (e.g., VGA text/graphics memory).
        $videoMax = self::VIDEO_MEM_MAX_EXCLUSIVE - 1;
        if (self::rangesOverlap($srcMin, $srcMax, self::VIDEO_MEM_MIN, $videoMax) ||
            self::rangesOverlap($dstMin, $dstMax, self::VIDEO_MEM_MIN, $videoMax)
        ) {
            return null;
        }

        // Stay away from common MMIO ranges (VBE LFB, APIC, etc.) in linear space.
        if ($srcMin < 0 || $dstMin < 0) {
            return null;
        }
        if ($srcMax >= 0xE0000000 || $dstMax >= 0xE0000000) {
            return null;
        }

        // Avoid overlap cases where REP MOVS semantics differ from memmove.
        if ($step > 0) {
            if ($dstMin > $srcMin && $dstMin <= $srcMax) {
                return null;
            }
        } else {
            if ($dstMax < $srcMax && $dstMax >= $srcMin) {
                return null;
            }
        }

        // memmove overlap detection: src < dst < src+len => copy backward.
        $copyBackward = ($dstMin > $srcMin) && ($dstMin < ($srcMin + $byteCount));

        return [
            'srcMin' => $srcMin,
            'srcMax' => $srcMax,
            'dstMin' => $dstMin,
            'dstMax' => $dstMax,
            'byteCount' => $byteCount,
            'copyBackward' => $copyBackward,
        ];
    }

    /**
     * Copy a linear byte range using paging translation (4KB chunks).
     *
     * Returns true only if the copy was performed; on any translation/MMIO issue this returns false
     * without modifying guest memory contents.
     */
    private function copyLinearRangeWithPaging(
        RuntimeInterface $runtime,
        int $srcLinear,
        int $dstLinear,
        int $byteCount,
        bool $copyBackward,
    ): bool {
        if ($byteCount <= 0) {
            return true;
        }

        $cpu = $runtime->context()->cpu();
        if (!$cpu->isPagingEnabled()) {
            return false;
        }

        // Only handle 32-bit linear addresses here.
        if (($srcLinear >> 32) !== 0 || ($dstLinear >> 32) !== 0) {
            return false;
        }

        $ma = $runtime->memoryAccessor();
        $memory = $runtime->memory();
        $linearMask = $this->linearMask($runtime);
        $isUser = $cpu->cpl() === 3;

        $isMmio = static function (int $addr): bool {
            return ($addr >= 0xE0000000 && $addr < 0xE1000000) ||
                ($addr >= 0xFEE00000 && $addr < 0xFEE01000) ||
                ($addr >= 0xFEC00000 && $addr < 0xFEC00020);
        };

        /** @var array<int, array{int,int,int}> */
        $segments = [];

        if ($copyBackward) {
            $remaining = $byteCount;
            while ($remaining > 0) {
                $srcEnd = ($srcLinear + $remaining - 1) & 0xFFFFFFFF;
                $dstEnd = ($dstLinear + $remaining - 1) & 0xFFFFFFFF;

                $srcPageOff = $srcEnd & (self::PAGE_SIZE - 1);
                $dstPageOff = $dstEnd & (self::PAGE_SIZE - 1);
                $chunk = min($remaining, min($srcPageOff, $dstPageOff) + 1);

                $srcStart = ($srcEnd - $chunk + 1) & 0xFFFFFFFF;
                $dstStart = ($dstEnd - $chunk + 1) & 0xFFFFFFFF;

                [$srcPhys, $srcErr] = $ma->translateLinear($srcStart, false, $isUser, true, $linearMask);
                if ($srcErr !== 0) {
                    return false;
                }
                [$dstPhys, $dstErr] = $ma->translateLinear($dstStart, true, $isUser, true, $linearMask);
                if ($dstErr !== 0) {
                    return false;
                }

                $srcPhys32 = ((int) $srcPhys) & 0xFFFFFFFF;
                $dstPhys32 = ((int) $dstPhys) & 0xFFFFFFFF;
                if ($isMmio($srcPhys32) || $isMmio($dstPhys32)) {
                    return false;
                }

                if (!$memory->ensureCapacity($srcPhys32 + $chunk)) {
                    return false;
                }
                if (!$memory->ensureCapacity($dstPhys32 + $chunk)) {
                    return false;
                }

                $segments[] = [$srcPhys32, $dstPhys32, $chunk];
                $remaining -= $chunk;
            }
        } else {
            $remaining = $byteCount;
            $srcPtr = $srcLinear & 0xFFFFFFFF;
            $dstPtr = $dstLinear & 0xFFFFFFFF;
            while ($remaining > 0) {
                $srcPageOff = $srcPtr & (self::PAGE_SIZE - 1);
                $dstPageOff = $dstPtr & (self::PAGE_SIZE - 1);
                $chunk = min($remaining, min(self::PAGE_SIZE - $srcPageOff, self::PAGE_SIZE - $dstPageOff));

                [$srcPhys, $srcErr] = $ma->translateLinear($srcPtr, false, $isUser, true, $linearMask);
                if ($srcErr !== 0) {
                    return false;
                }
                [$dstPhys, $dstErr] = $ma->translateLinear($dstPtr, true, $isUser, true, $linearMask);
                if ($dstErr !== 0) {
                    return false;
                }

                $srcPhys32 = ((int) $srcPhys) & 0xFFFFFFFF;
                $dstPhys32 = ((int) $dstPhys) & 0xFFFFFFFF;
                if ($isMmio($srcPhys32) || $isMmio($dstPhys32)) {
                    return false;
                }

                if (!$memory->ensureCapacity($srcPhys32 + $chunk)) {
                    return false;
                }
                if (!$memory->ensureCapacity($dstPhys32 + $chunk)) {
                    return false;
                }

                $segments[] = [$srcPhys32, $dstPhys32, $chunk];
                $srcPtr = ($srcPtr + $chunk) & 0xFFFFFFFF;
                $dstPtr = ($dstPtr + $chunk) & 0xFFFFFFFF;
                $remaining -= $chunk;
            }
        }

        $physicalMemory = $memory instanceof PagedMemoryStream ? $memory->physicalStream() : $memory;
        foreach ($segments as [$srcPhys32, $dstPhys32, $chunk]) {
            $physicalMemory->copy($physicalMemory, $srcPhys32, $dstPhys32, $chunk);
        }

        return true;
    }

    /**
     * Compute byte ranges for REP MOVS operations.
     *
     * @return array{int,int,int} [srcMinOrDstMin, srcMaxOrDstMax, byteCount]
     */
    private function bulkMovsRange(int $srcOrDstLinear, int $count, int $width, int $step): array
    {
        $byteCount = $count * $width;
        if ($step > 0) {
            $min = $srcOrDstLinear;
            $max = $srcOrDstLinear + $byteCount - 1;
            return [$min, $max, $byteCount];
        }

        $min = $srcOrDstLinear - (($count - 1) * $width);
        $max = $srcOrDstLinear + ($width - 1);
        return [$min, $max, $byteCount];
    }

    /**
     * Compute the contiguous destination range for bulk STOS.
     *
     * @return array{int,int} [dstMin, byteCount]
     */
    private function bulkStosRange(int $dstLinear, int $count, int $width, int $step): array
    {
        $byteCount = $count * $width;
        if ($step > 0) {
            return [$dstLinear, $byteCount];
        }
        return [$dstLinear - (($count - 1) * $width), $byteCount];
    }

    private function canFastBulkStos(
        RuntimeInterface $runtime,
        int $di,
        int $dstLinear,
        int $count,
        int $width,
        int $step,
    ): bool {
        if ($count <= 0) {
            return false;
        }

        $cpu = $runtime->context()->cpu();
        if ($cpu->isPagingEnabled()) {
            return false;
        }
        if (!$cpu->isLongMode() && !$cpu->isA20Enabled()) {
            return false;
        }

        $addrSize = $cpu->addressSize();
        if ($addrSize === 16) {
            $delta = ($count - 1) * $width;
            if ($step > 0) {
                if ($di + $delta > 0xFFFF) {
                    return false;
                }
            } else {
                if ($di - $delta < 0) {
                    return false;
                }
            }
        }

        [$dstMin, $byteCount] = $this->bulkStosRange($dstLinear, $count, $width, $step);
        $dstMax = $dstMin + $byteCount - 1;

        if ($dstMin < 0) {
            return false;
        }
        $videoMax = self::VIDEO_MEM_MAX_EXCLUSIVE - 1;
        if (self::rangesOverlap($dstMin, $dstMax, self::VIDEO_MEM_MIN, $videoMax)) {
            return false;
        }
        if ($dstMax >= 0xE0000000) {
            return false;
        }

        if (!$runtime->memory()->ensureCapacity($dstMin + $byteCount)) {
            return false;
        }
        return true;
    }

    /**
     * REPE/REPNE CMPSB - bulk memory compare (byte)
     * For REPE (0xF3): find first mismatch (ZF=0)
     * For REPNE (0xF2): find first match (ZF=1)
     * Uses same read methods as Cmpsb.php for correctness
     */
    private function bulkCmpsb(RuntimeInterface $runtime, int $count, int $opcode): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);
        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Calculate segment:offset addresses (not linear yet)
        $srcSegOff = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Compare byte by byte using readMemory8 (same as Cmpsb.php)
        $processed = 0;
        $left = 0;
        $right = 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $i;
            $left = $this->readMemory8($runtime, $srcSegOff + $offset);
            $right = $this->readMemory8($runtime, $dstSegOff + $offset);
            $processed++;

            $match = ($left === $right);
            if ($opcode === 0xF3 && !$match) {
                // REPE: stop at first mismatch
                break;
            }
            if ($opcode === 0xF2 && $match) {
                // REPNE: stop at first match
                break;
            }
        }

        // Update flags based on last comparison (same as Cmpsb.php line 36-46)
        $calc = $left - $right;
        $result = $calc & 0xFF;
        $af = (($left & 0x0F) < ($right & 0x0F));
        $signA = ($left >> 7) & 1;
        $signB = ($right >> 7) & 1;
        $signR = ($result >> 7) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $ma->updateFlags($result, 8)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        // Update registers
        $totalStep = $step * $processed;
        $this->writeIndex($runtime, RegisterType::ESI, $si + $totalStep);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, $count - $processed);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * REPE/REPNE CMPSW/CMPSD - bulk memory compare (word/dword)
     * Uses same read methods as Cmpsw.php for correctness
     */
    private function bulkCmpsw(RuntimeInterface $runtime, int $count, int $opcode): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $opSize = $runtime->context()->cpu()->operandSize();
        $width = match ($opSize) {
            16 => 2,
            32 => 4,
            64 => 8,
            default => 2,
        };
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);
        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Calculate segment:offset addresses (not linear yet)
        $srcSegOff = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Compare word by word using readMemory16/32 (same as Cmpsw.php)
        $processed = 0;
        $left = 0;
        $right = 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $width * $i;
            $left = match ($opSize) {
                16 => $this->readMemory16($runtime, $srcSegOff + $offset),
                32 => $this->readMemory32($runtime, $srcSegOff + $offset),
                64 => $this->readMemory64($runtime, $srcSegOff + $offset)->toInt(),
                default => $this->readMemory16($runtime, $srcSegOff + $offset),
            };
            $right = match ($opSize) {
                16 => $this->readMemory16($runtime, $dstSegOff + $offset),
                32 => $this->readMemory32($runtime, $dstSegOff + $offset),
                64 => $this->readMemory64($runtime, $dstSegOff + $offset)->toInt(),
                default => $this->readMemory16($runtime, $dstSegOff + $offset),
            };
            $processed++;

            $match = ($left === $right);
            if ($opcode === 0xF3 && !$match) {
                // REPE: stop at first mismatch
                break;
            }
            if ($opcode === 0xF2 && $match) {
                // REPNE: stop at first match
                break;
            }
        }

        if ($opSize === 64) {
            $leftU = UInt64::of($left);
            $rightU = UInt64::of($right);
            $resultU = $leftU->sub($rightU);
            $resultInt = $resultU->toInt();

            $cf = $leftU->lt($rightU);
            $af = (($left & 0x0F) < ($right & 0x0F));
            $of = (($left < 0) !== ($right < 0)) && (($resultInt < 0) === ($right < 0));

            $ma->updateFlags($resultInt, 64)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        } else {
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $leftU = $left & $mask;
            $rightU = $right & $mask;

            $calc = $leftU - $rightU;
            $result = $calc & $mask;
            $cf = $calc < 0;
            $af = (($leftU & 0x0F) < ($rightU & 0x0F));

            $signA = ($leftU >> $signBit) & 1;
            $signB = ($rightU >> $signBit) & 1;
            $signR = ($result >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);

            $ma->updateFlags($result, $opSize)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        // Update registers
        $totalStep = $step * $width * $processed;
        $this->writeIndex($runtime, RegisterType::ESI, $si + $totalStep);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, $count - $processed);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * REPE/REPNE SCASB - bulk memory scan (byte)
     * For REPE (0xF3): find first byte != AL
     * For REPNE (0xF2): find first byte == AL
     * Uses same read method as Scasb.php for correctness
     */
    private function bulkScasb(RuntimeInterface $runtime, int $count, int $opcode): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $al = $ma->fetch(RegisterType::EAX)->asLowBit();
        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Calculate segment:offset address (not linear yet)
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Scan byte by byte using readMemory8 (same as Scasb.php)
        $processed = 0;
        $value = 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $i;
            $value = $this->readMemory8($runtime, $dstSegOff + $offset);
            $processed++;

            $match = ($value === $al);
            if ($opcode === 0xF2 && $match) {
                // REPNE: stop at first match
                break;
            }
            if ($opcode === 0xF3 && !$match) {
                // REPE: stop at first mismatch
                break;
            }
        }

        // Update flags based on last comparison (same as Scasb.php line 30-40)
        $calc = $al - $value;
        $result = $calc & 0xFF;
        $af = (($al & 0x0F) < ($value & 0x0F));
        $signA = ($al >> 7) & 1;
        $signB = ($value >> 7) & 1;
        $signR = ($result >> 7) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $ma->updateFlags($result, 8)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of)
            ->setAuxiliaryCarryFlag($af);

        // Update registers
        $totalStep = $step * $processed;
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, $count - $processed);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * REPE/REPNE SCASW/SCASD - bulk memory scan (word/dword)
     * Uses same read method as Scasw.php for correctness
     */
    private function bulkScasw(RuntimeInterface $runtime, int $count, int $opcode): ?ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $step = $ma->shouldDirectionFlag() ? -1 : 1;

        $opSize = $runtime->context()->cpu()->operandSize();
        $width = match ($opSize) {
            16 => 2,
            32 => 4,
            64 => 8,
            default => 2,
        };
        $ax = $ma->fetch(RegisterType::EAX)->asBytesBySize($opSize);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Calculate segment:offset address (not linear yet)
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Scan word by word using readMemory16/32 (same as Scasw.php)
        $processed = 0;
        $value = 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $width * $i;
            $value = match ($opSize) {
                16 => $this->readMemory16($runtime, $dstSegOff + $offset),
                32 => $this->readMemory32($runtime, $dstSegOff + $offset),
                64 => $this->readMemory64($runtime, $dstSegOff + $offset)->toInt(),
                default => $this->readMemory16($runtime, $dstSegOff + $offset),
            };
            $processed++;

            $match = ($value === $ax);
            if ($opcode === 0xF2 && $match) {
                // REPNE: stop at first match
                break;
            }
            if ($opcode === 0xF3 && !$match) {
                // REPE: stop at first mismatch
                break;
            }
        }

        if ($opSize === 64) {
            $axU = UInt64::of($ax);
            $valueU = UInt64::of($value);
            $resultU = $axU->sub($valueU);
            $resultInt = $resultU->toInt();

            $cf = $axU->lt($valueU);
            $af = (($ax & 0x0F) < ($value & 0x0F));
            $of = (($ax < 0) !== ($value < 0)) && (($resultInt < 0) === ($value < 0));

            $ma->updateFlags($resultInt, 64)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        } else {
            $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
            $signBit = $opSize === 32 ? 31 : 15;
            $axU = $ax & $mask;
            $valueU = $value & $mask;

            $calc = $axU - $valueU;
            $result = $calc & $mask;
            $cf = $calc < 0;
            $af = (($axU & 0x0F) < ($valueU & 0x0F));

            $signA = ($axU >> $signBit) & 1;
            $signB = ($valueU >> $signBit) & 1;
            $signR = ($result >> $signBit) & 1;
            $of = ($signA !== $signB) && ($signB === $signR);

            $ma->updateFlags($result, $opSize)
                ->setCarryFlag($cf)
                ->setOverflowFlag($of)
                ->setAuxiliaryCarryFlag($af);
        }

        // Update registers
        $totalStep = $step * $width * $processed;
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, $count - $processed);

        return ExecutionStatus::SUCCESS;
    }
}
