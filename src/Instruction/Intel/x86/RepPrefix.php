<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class RepPrefix implements InstructionInterface
{
    use Instructable;

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

    /**
     * Instructions that should NOT be repeated even with REP prefix.
     * REP acts as a hint/padding for these instructions.
     * - REP RET (0xF3 0xC3): AMD branch prediction bug workaround
     * - REP NOP (0xF3 0x90): PAUSE instruction for spin-wait loops
     */
    private const NON_REPEATING_INSTRUCTIONS = [
        Ret::class,
        Nop::class,
    ];

    private static ?bool $traceGrubCfgCopy = null;
    private static bool $tracedGrubCfgCopy = false;

    public function opcodes(): array
    {
        return [0xF3, 0xF2];
    }

    private static function shouldTraceGrubCfgCopy(): bool
    {
        if (self::$traceGrubCfgCopy !== null) {
            return self::$traceGrubCfgCopy;
        }

        $env = getenv('PHPME_TRACE_GRUBCFG_COPY');
        self::$traceGrubCfgCopy = $env !== false && trim($env) !== '' && trim($env) !== '0';
        return self::$traceGrubCfgCopy;
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
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

            // Check if this is a non-repeating instruction (REP RET, REP NOP/PAUSE)
            if ($lastInstruction !== null) {
                foreach (self::NON_REPEATING_INSTRUCTIONS as $nonRepClass) {
                    if ($lastInstruction instanceof $nonRepClass) {
                        $this->writeIndex($runtime, RegisterType::ECX, $counter + 1);
                        return $result;
                    }
                }
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

        if (!self::$tracedGrubCfgCopy && self::shouldTraceGrubCfgCopy()) {
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

                self::$tracedGrubCfgCopy = true;
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

        if (!self::$tracedGrubCfgCopy && self::shouldTraceGrubCfgCopy()) {
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

                self::$tracedGrubCfgCopy = true;
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

        // Update registers
        $totalStep = $step * $width * $count;
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

        return ExecutionStatus::SUCCESS;
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
