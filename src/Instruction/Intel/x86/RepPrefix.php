<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

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

    public function opcodes(): array
    {
        return [0xF3, 0xF2];
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

            // Bulk optimization - only when not crossing page boundaries (4KB)
            $opcode = $opcodes[0];
            if ($counter > 0 && $lastInstruction !== null) {
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

        // Process each byte using the same methods as Movsb.php
        for ($i = 0; $i < $count; $i++) {
            // Read using readMemory8 (same as Movsb.php line 27-30)
            $value = $this->readMemory8($runtime, $srcSegOff + ($step * $i));

            // Write using writeRawByte after allocate (same as Movsb.php line 32-34)
            $destAddress = $this->translateLinear($runtime, $dstSegOff + ($step * $i), true);
            $ma->allocate($destAddress, safe: false);
            $ma->writeRawByte($destAddress, $value);
        }

        // Update registers
        $totalStep = $step * $count;
        $this->writeIndex($runtime, RegisterType::ESI, $si + $totalStep);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

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
        $width = $opSize === 32 ? 4 : 2;
        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);
        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        // Calculate segment:offset addresses (not linear yet)
        $srcSegOff = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Process each element using the same methods as Movsw.php
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $width * $i;

            // Read using readMemory16/32 (same as Movsw.php line 30-32)
            $value = $opSize === 32
                ? $this->readMemory32($runtime, $srcSegOff + $offset)
                : $this->readMemory16($runtime, $srcSegOff + $offset);

            // Write using writeBySize after allocate (same as Movsw.php line 34-36)
            $destAddress = $this->translateLinear($runtime, $dstSegOff + $offset, true);
            $ma->allocate($destAddress, $width, safe: false);
            $ma->writeBySize($destAddress, $value, $opSize);
        }

        // Update registers
        $totalStep = $step * $width * $count;
        $this->writeIndex($runtime, RegisterType::ESI, $si + $totalStep);
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, 0);

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
            $address = $this->translateLinear($runtime, $dstSegOff + ($step * $i), true);
            $ma->allocate($address, safe: false);
            $ma->writeRawByte($address, $byte);
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
        $width = $opSize === 32 ? 4 : 2;
        $value = $ma->fetch(RegisterType::EAX)->asBytesBySize($opSize);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Calculate segment:offset address (not linear yet)
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Process each element using the same methods as Stosw.php
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $width * $i;

            // Write using writeBySize after allocate (same as Stosw.php line 30-31)
            $address = $this->translateLinear($runtime, $dstSegOff + $offset, true);
            $ma->allocate($address, safe: false);
            $ma->writeBySize($address, $value, $opSize);
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
        $signA = ($left >> 7) & 1;
        $signB = ($right >> 7) & 1;
        $signR = ($result >> 7) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $ma->updateFlags($result, 8)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of);

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
        $width = $opSize === 32 ? 4 : 2;
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
            $left = $opSize === 32
                ? $this->readMemory32($runtime, $srcSegOff + $offset)
                : $this->readMemory16($runtime, $srcSegOff + $offset);
            $right = $opSize === 32
                ? $this->readMemory32($runtime, $dstSegOff + $offset)
                : $this->readMemory16($runtime, $dstSegOff + $offset);
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

        // Update flags based on last comparison (same as Cmpsw.php line 39-51)
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $calc = $left - $right;
        $result = $calc & $mask;
        $signA = ($left >> $signBit) & 1;
        $signB = ($right >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $ma->updateFlags($result, $opSize)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of);

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
        $signA = ($al >> 7) & 1;
        $signB = ($value >> 7) & 1;
        $signR = ($result >> 7) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $ma->updateFlags($result, 8)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of);

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
        $width = $opSize === 32 ? 4 : 2;
        $ax = $ma->fetch(RegisterType::EAX)->asBytesBySize($opSize);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Calculate segment:offset address (not linear yet)
        $dstSegOff = $this->segmentOffsetAddress($runtime, RegisterType::ES, $di);

        // Scan word by word using readMemory16/32 (same as Scasw.php)
        $processed = 0;
        $value = 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = $step * $width * $i;
            $value = $opSize === 32
                ? $this->readMemory32($runtime, $dstSegOff + $offset)
                : $this->readMemory16($runtime, $dstSegOff + $offset);
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

        // Update flags based on last comparison (same as Scasw.php line 33-45)
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $signBit = $opSize === 32 ? 31 : 15;
        $calc = $ax - $value;
        $result = $calc & $mask;
        $signA = ($ax >> $signBit) & 1;
        $signB = ($value >> $signBit) & 1;
        $signR = ($result >> $signBit) & 1;
        $of = ($signA !== $signB) && ($signB === $signR);
        $ma->updateFlags($result, $opSize)
            ->setCarryFlag($calc < 0)
            ->setOverflowFlag($of);

        // Update registers
        $totalStep = $step * $width * $processed;
        $this->writeIndex($runtime, RegisterType::EDI, $di + $totalStep);
        $this->writeIndex($runtime, RegisterType::ECX, $count - $processed);

        return ExecutionStatus::SUCCESS;
    }
}
