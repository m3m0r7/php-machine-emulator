<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Loop implements InstructionInterface
{
    use Instructable;

    /**
     * Cache for detected byte-copy loop patterns.
     * Key: loop start address, Value: pattern info array or false if not a pattern
     * @var array<int, array|false>
     */
    private array $patternCache = [];

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE2]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $operand = $runtime
                ->memory()
            ->signedByte();

        $pos = $runtime
                ->memory()
            ->offset();

        // LOOP decrements ECX first, then checks if non-zero
        $size = $runtime->context()->cpu()->addressSize();
        $counter = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::ECX)
            ->asBytesBySize($size);

        // Optimization: Empty loop detection (LOOP to itself or very small backward jump)
        // If operand is -2 (jump back to the LOOP instruction itself), skip entire loop
        if ($operand === -2 && $counter > 1) {
            // This is a delay loop - skip it entirely
            $runtime->memoryAccessor()
                ->writeBySize(RegisterType::ECX, 0, $size);
            return ExecutionStatus::SUCCESS;
        }

        $loopTarget = $pos + $operand;

        // Optimization: Detect and bulk execute simple byte copy loops
        // Pattern: MOV AL, [source] / CALL output_routine / LOOP
        // Only optimize when source and destination do NOT overlap.
        $patternConfig = $runtime->logicBoard()->debug()->patterns();
        $patternEnabled = $patternConfig->enableLzmaPattern && $patternConfig->enableLzmaLoopOptimization;
        if ($patternEnabled && $counter > 7 && $operand >= -15 && $operand <= -8) {
            $result = $this->tryBulkByteCopyLoop($runtime, $loopTarget, $pos, $counter, $size);
            if ($result !== null) {
                return $result;
            }
        }

        $counter = ($counter - 1) & ($size === 32 ? 0xFFFFFFFF : 0xFFFF);

        // Write decremented value back
        $runtime->memoryAccessor()
            ->writeBySize(RegisterType::ECX, $counter, $size);

        $runtime->option()->logger()->debug(sprintf('LOOP: counter=%d, operand=%d, pos=0x%X, target=0x%X',
            $counter, $operand, $pos, $pos + $operand));

        // Jump if counter is non-zero
        if ($counter === 0) {
            return ExecutionStatus::SUCCESS;
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->memory()
                ->setOffset($pos + $operand);
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Try to detect and bulk execute a byte-copy loop pattern.
     *
     * Common pattern in LZMA decompression:
     * loop_start:
     *   MOV AL, [ESI]      ; 0x8A 0x06 or similar
     *   CALL output_byte   ; 0xE8 xx xx xx xx
     *   LOOP loop_start    ; 0xE2 xx
     *
     * Where output_byte does:
     *   MOV [EBX], CL      ; or similar store
     *   STOSB              ; store AL to [EDI] and increment EDI
     *   INC dword [addr]   ; increment counter
     *   RET
     *
     * @return ExecutionStatus|null Returns ExecutionStatus if bulk executed, null to fall through
     */
    private function tryBulkByteCopyLoop(
        RuntimeInterface $runtime,
        int $loopTarget,
        int $loopPos,
        int $counter,
        int $size
    ): ?ExecutionStatus {
        // Check pattern cache first
        if (isset($this->patternCache[$loopTarget])) {
            $pattern = $this->patternCache[$loopTarget];
            if ($pattern === false) {
                return null; // Not a recognized pattern
            }
            return $this->executeBulkByteCopy($runtime, $pattern, $counter, $loopPos, $size);
        }

        // Detect pattern: look for MOV AL, [ESI] followed by CALL
        $memory = $runtime->memory();
        $originalOffset = $memory->offset();

        try {
            $memory->setOffset($loopTarget);

            // Read first instruction (should be MOV AL, [source])
            $byte1 = $memory->byte();

            // Check for MOV r8, r/m8 (opcode 0x8A)
            if ($byte1 !== 0x8A) {
                $this->patternCache[$loopTarget] = false;
                return null;
            }

            $modrm = $memory->byte();
            $mod = ($modrm >> 6) & 0x03;
            $reg = ($modrm >> 3) & 0x07;
            $rm = $modrm & 0x07;

            // We need MOV AL, [something] - reg should be 0 (AL)
            if ($reg !== 0) {
                $this->patternCache[$loopTarget] = false;
                return null;
            }

            // Handle SIB byte case (rm=4 in 32-bit mode)
            $sourceReg = null;
            $useSib = false;
            $sibBase = null;
            $sibIndex = null;
            $sibScale = 1;

            if ($rm === 4 && $mod !== 3) {
                // SIB byte follows
                $useSib = true;
                $sib = $memory->byte();
                $sibScale = 1 << (($sib >> 6) & 0x03);
                $sibIndex = ($sib >> 3) & 0x07;
                $sibBase = $sib & 0x07;

                // For LZMA pattern: typically [EDI + EDX*1] where index=2 (EDX), base=7 (EDI)
                // We'll use EDI as the primary source address
                if ($sibBase === 7) { // EDI
                    $sourceReg = RegisterType::EDI;
                } elseif ($sibBase === 6) { // ESI
                    $sourceReg = RegisterType::ESI;
                } else {
                    $this->patternCache[$loopTarget] = false;
                    return null;
                }
            } else {
                // Determine source register based on mod/rm
                // Common patterns: [ESI] (rm=6, mod=0), [ESI+disp8], [EBX], etc.
                $sourceReg = match ($rm) {
                    0 => RegisterType::EAX,
                    1 => RegisterType::ECX,
                    2 => RegisterType::EDX,
                    3 => RegisterType::EBX,
                    5 => $mod === 0 ? null : RegisterType::EBP, // disp32 if mod=0
                    6 => RegisterType::ESI,
                    7 => RegisterType::EDI,
                    default => null,
                };
            }

            if ($sourceReg === null) {
                $this->patternCache[$loopTarget] = false;
                return null;
            }

            // Skip displacement bytes if present
            if ($mod === 1) {
                $memory->byte(); // disp8
            } elseif ($mod === 2 || ($mod === 0 && $rm === 5)) {
                $memory->dword(); // disp32
            }

            // Next should be CALL (0xE8)
            $callOpcode = $memory->byte();
            if ($callOpcode !== 0xE8) {
                $this->patternCache[$loopTarget] = false;
                return null;
            }

            // Read CALL offset (32-bit relative)
            $callOffsetUnsigned = $memory->dword();
            // Convert to signed
            $callOffset = $callOffsetUnsigned > 0x7FFFFFFF
                ? $callOffsetUnsigned - 0x100000000
                : $callOffsetUnsigned;
            $afterCall = $memory->offset();
            $callTarget = ($afterCall + $callOffset) & 0xFFFFFFFF;

            // Verify this is a simple output routine by checking it contains STOSB
            // We'll trust it for now and cache the pattern
            $pattern = [
                'sourceReg' => $sourceReg,
                'sourceMod' => $mod,
                'callTarget' => $callTarget,
                'afterCall' => $afterCall,
                'useSib' => $useSib,
                'sibIndex' => $sibIndex,
                'sibScale' => $sibScale,
            ];

            $this->patternCache[$loopTarget] = $pattern;

            $runtime->option()->logger()->debug(sprintf(
                'LOOP: Detected bulk byte-copy pattern at 0x%X, source=%s, callTarget=0x%X, counter=%d',
                $loopTarget, $sourceReg->name, $callTarget, $counter
            ));

            return $this->executeBulkByteCopy($runtime, $pattern, $counter, $loopPos, $size);

        } finally {
            // Restore original position if we didn't bulk execute
            $memory->setOffset($originalOffset);
        }
    }

    /**
     * Execute the bulk byte copy operation.
     * @return ExecutionStatus|null Returns null if cannot be bulk executed
     */
    private function executeBulkByteCopy(
        RuntimeInterface $runtime,
        array $pattern,
        int $counter,
        int $loopPos,
        int $size
    ): ?ExecutionStatus {
        $ma = $runtime->memoryAccessor();
        $sourceReg = $pattern['sourceReg'];
        $useSib = $pattern['useSib'] ?? false;
        $sibIndex = $pattern['sibIndex'] ?? null;
        $sibScale = $pattern['sibScale'] ?? 1;

        // Get source base address
        $sourceBase = $ma->fetch($sourceReg)->asBytesBySize(32);

        // If using SIB, calculate actual source address
        // Pattern is typically [EDI + EDX*1] for LZMA copy-from-history
        $indexReg = null;
        $indexValue = 0;
        if ($useSib && $sibIndex !== null && $sibIndex !== 4) { // index=4 means no index
            $indexReg = match ($sibIndex) {
                0 => RegisterType::EAX,
                1 => RegisterType::ECX,
                2 => RegisterType::EDX,
                3 => RegisterType::EBX,
                5 => RegisterType::EBP,
                6 => RegisterType::ESI,
                7 => RegisterType::EDI,
                default => null,
            };
            if ($indexReg !== null) {
                $indexValue = $ma->fetch($indexReg)->asBytesBySize(32);
                // Sign extend if negative
                if ($indexValue > 0x7FFFFFFF) {
                    $indexValue = $indexValue - 0x100000000;
                }
            }

        }

        // Calculate initial source address
        $sourceAddr = ($sourceBase + ($indexValue * $sibScale)) & 0xFFFFFFFF;

        // For LZMA history copy pattern [EDI + EDX*1]:
        // The addressing mode reads from [currentEDI + EDX] where currentEDI advances each iteration.
        // If EDX is negative (copying from history), and |EDX| <= counter, then
        // later iterations will read bytes written by earlier iterations of the same loop.
        // This is a self-referencing pattern and CANNOT be bulk optimized.
        //
        // Example: counter=10, EDX=-6
        // - Iteration 0: reads from EDI+0+(-6) = EDI-6, writes to EDI+0
        // - Iteration 6: reads from EDI+6+(-6) = EDI+0, writes to EDI+6
        //   -> This reads the byte written in iteration 0!
        //
        // We can only optimize if |EDX| > counter (no overlap during the loop)
        if ($useSib && $indexValue < 0 && (-$indexValue) <= $counter) {
            // Self-referencing pattern - fall back to normal execution
            return null;
        }

        // Also skip if EDX is 0 (copying from current position - usually a repeat pattern)
        if ($useSib && $indexValue === 0) {
            return null;
        }

        // Validate source address is reasonable (in low memory, not an overflow)
        // LZMA typically works with distances up to 32KB (0x8000) for history buffer
        if ($useSib && ($sourceAddr > 0x80000000 || $sourceAddr > $sourceBase)) {
            // Source address overflow or wraps around - fall back to normal execution
            return null;
        }

        // Get destination (EDI for STOSB)
        $destAddr = $ma->fetch(RegisterType::EDI)->asBytesBySize(32);

        // Direction flag affects STOSB direction
        $df = $ma->shouldDirectionFlag();
        $step = $df ? -1 : 1;

        $runtime->option()->logger()->debug(sprintf(
            'LOOP: Bulk copying %d bytes from 0x%08X to 0x%08X (step=%d, sib=%d)',
            $counter, $sourceAddr, $destAddr, $step, $useSib ? 1 : 0
        ));

        // Fast path: contiguous forward copy with no overlap.
        if ($step === 1) {
            $srcStart = $sourceAddr & 0xFFFFFFFF;
            $dstStart = $destAddr & 0xFFFFFFFF;
            $len = $counter & 0xFFFFFFFF;
            if ($len > 0) {
                $srcEnd = ($srcStart + $len - 1) & 0xFFFFFFFF;
                $dstEnd = ($dstStart + $len - 1) & 0xFFFFFFFF;
                $overlap = !($dstEnd < $srcStart || $dstStart > $srcEnd);
                $memory = $runtime->memory();
                if (!$overlap
                    && $memory->ensureCapacity($srcEnd + 1)
                    && $memory->ensureCapacity($dstEnd + 1)
                ) {
                    $memory->copy($memory, $srcStart, $dstStart, $len);
                    // Update EDI and ECX below, then return.
                    $newDest = ($dstStart + $len) & 0xFFFFFFFF;
                    $ma->writeBySize(RegisterType::EDI, $newDest, 32);
                    $ma->writeBySize(RegisterType::ECX, 0, $size);
                    if ($len > 0) {
                        $lastByte = $this->readMemory8($runtime, $srcStart + $len - 1);
                        $ma->writeToLowBit(RegisterType::EAX, $lastByte);
                    }
                    if ($runtime->option()->shouldChangeOffset()) {
                        $runtime->memory()->setOffset($loopPos);
                    }
                    return ExecutionStatus::SUCCESS;
                }
            }
        }

        // Fallback: per-byte copy.
        for ($i = 0; $i < $counter; $i++) {
            $currentSource = ($sourceAddr + ($i * $step)) & 0xFFFFFFFF;
            $byte = $this->readMemory8($runtime, $currentSource);

            $currentDest = ($destAddr + ($i * $step)) & 0xFFFFFFFF;
            $ma->allocate($currentDest, safe: false);
            $ma->writeRawByte($currentDest, $byte);
        }

        // Update EDI (destination pointer) - STOSB increments/decrements
        $newDest = ($destAddr + ($counter * $step)) & 0xFFFFFFFF;
        $ma->writeBySize(RegisterType::EDI, $newDest, 32);

        // Update AL to last byte copied (for compatibility)
        if ($counter > 0) {
            $lastSource = ($sourceAddr + (($counter - 1) * $step)) & 0xFFFFFFFF;
            $lastByte = $this->readMemory8($runtime, $lastSource);
            $ma->writeToLowBit(RegisterType::EAX, $lastByte);
        }

        // Set ECX to 0 (loop finished)
        $ma->writeBySize(RegisterType::ECX, 0, $size);

        // Set instruction pointer to after the LOOP instruction
        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->memory()->setOffset($loopPos);
        }

        return ExecutionStatus::SUCCESS;
    }
}
