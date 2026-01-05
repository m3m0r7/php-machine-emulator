<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\NotFoundInstructionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\Intel\TranslationBlock;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\PatternedInstructionsList;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\PatternedInstructionsListStats;
use PHPMachineEmulator\UEFI\UEFIRuntimeRegistry;

class InstructionExecutor implements InstructionExecutorInterface
{
    private ?InstructionInterface $lastInstruction = null;
    private ?array $lastOpcodes = null;
    private int $lastInstructionPointer = 0;
    private int $prevInstructionPointer = 0;
    private int $zeroOpcodeCount = 0;

    /**
     * Instruction decode cache: IP => [instruction, opcodes, length]
     * @var array<int, array{InstructionInterface, array<int>, int}>
     */
    private array $decodeCache = [];

    /**
     * Pages that have been executed (for best-effort self-modifying code handling).
     * @var array<int,true>
     */
    private array $executedPages = [];

    /**
     * Execution hit count per IP for hotspot detection
     * @var array<int, int>
     */
    private array $hitCount = [];

    private int $kernelProbeCountdown = 0;
    private int $kernelProbeLogCount = 0;

    /**
     * Debug/helper state (trace/stop logic, counters).
     */
    private ?InstructionExecutorDebug $debug = null;

    /**
     * Translation Blocks: startIP => TranslationBlock
     * @var array<int, TranslationBlock>
     */
    private array $translationBlocks = [];

    /**
     * Patterned instructions list for optimizing frequently-executed code patterns
     */
    private ?PatternedInstructionsList $patternedInstructionsList = null;

    private const HOTSPOT_THRESHOLD = 1;
    private const KERNEL_DECOMPRESS_HIT_THRESHOLD = 2000;
    private const KERNEL_DECOMPRESS_PROBE_EVERY = 100;

    public function __construct()
    {
        $this->kernelProbeCountdown = self::KERNEL_DECOMPRESS_PROBE_EVERY;
    }

    private function maybeProbeKernelDecompress(RuntimeInterface $runtime, int $ip): ?ExecutionStatus
    {
        if ($this->kernelProbeCountdown > 0) {
            $this->kernelProbeCountdown--;
            return null;
        }

        $this->kernelProbeCountdown = self::KERNEL_DECOMPRESS_PROBE_EVERY;
        if ($this->kernelProbeLogCount < 50) {
            $runtime->option()->logger()->warning(sprintf(
                'KERNEL_PROBE: ip=0x%08X',
                $ip & 0xFFFFFFFF,
            ));
            $this->kernelProbeLogCount++;
        }
        $env = UEFIRuntimeRegistry::environment($runtime);
        if ($env !== null && $env->maybeFastDecompressKernel($runtime, $ip, self::KERNEL_DECOMPRESS_HIT_THRESHOLD)) {
            return ExecutionStatus::SUCCESS;
        }

        return null;
    }

    public function execute(RuntimeInterface $runtime): ExecutionStatus
    {
        $ip = $runtime->memory()->offset();
        $runtime->context()->cpu()->syncCompatibilityModeWithCs();
        $this->prevInstructionPointer = $this->lastInstructionPointer;
        $this->lastInstructionPointer = $ip;
        $this->executedPages[($ip & 0xFFFFFFFF) >> 12] = true;
        $debug = $this->debug($runtime);
        $debug->maybeStopAtIp($runtime, $ip, $this->prevInstructionPointer, $this->lastInstruction, $this->lastOpcodes);
        $debug->maybeStopOnRspBelow($runtime, $ip, $this->prevInstructionPointer);

        $probeStatus = $this->maybeProbeKernelDecompress($runtime, $ip);
        if ($probeStatus !== null) {
            return $probeStatus;
        }

        // REP/iteration handler active? Fall back to single-step to keep lastInstruction accurate.
        if ($runtime->context()->cpu()->iteration()->isActive()) {
            return $this->executeSingleInstruction($runtime, $ip);
        }

        // Try hot pattern detection first (fastest path for known patterns)
        $patternResult = $this->patterns($runtime)->tryExecutePattern($runtime, $ip);
        if ($patternResult !== null && $patternResult->isSuccess()) {
            $debug->recordExecution($runtime, $ip);
            $debug->maybeTraceControlFlowTarget($runtime, $ip, $patternResult->ip(), 'pattern');
            $debug->maybeStopOnControlFlowTarget($runtime, $ip, $patternResult->ip(), null, null, 'pattern');
            return $patternResult->executionStatus();
        }

        // Execute existing translation block if present
        if (isset($this->translationBlocks[$ip])) {
            return $this->executeBlock($runtime, $this->translationBlocks[$ip]);
        }

        // Hotspot detection: count hits per IP
        $hits = ($this->hitCount[$ip] ?? 0) + 1;
        $this->hitCount[$ip] = $hits;

        if ($hits === self::KERNEL_DECOMPRESS_HIT_THRESHOLD) {
            $env = UEFIRuntimeRegistry::environment($runtime);
            if ($env !== null && $env->maybeFastDecompressKernel($runtime, $ip, $hits)) {
                return ExecutionStatus::SUCCESS;
            }
        }

        if ($hits >= self::HOTSPOT_THRESHOLD) {
            $block = $this->buildTranslationBlock($runtime, $ip);
            if ($block !== null) {
                $this->translationBlocks[$ip] = $block;
                return $this->executeBlock($runtime, $block);
            }
        }

        // Normal single-instruction execution (with decode cache)
        return $this->executeSingleInstruction($runtime, $ip);
    }

    private function debug(RuntimeInterface $runtime): InstructionExecutorDebug
    {
        if ($this->debug === null) {
            $this->debug = new InstructionExecutorDebug($runtime->logicBoard()->debug());
        }

        return $this->debug;
    }

    private function patterns(RuntimeInterface $runtime): PatternedInstructionsList
    {
        if ($this->patternedInstructionsList === null) {
            $this->patternedInstructionsList = new PatternedInstructionsList(
                $runtime->logicBoard()->debug()->patterns(),
            );
        }

        return $this->patternedInstructionsList;
    }

    /**
     * Execute a single instruction, using decode cache if available.
     */
    private function executeSingleInstruction(RuntimeInterface $runtime, int $ip): ExecutionStatus
    {
        $memory = $runtime->memory();
        $memoryAccessor = $runtime->memoryAccessor();
        $debug = $this->debug($runtime);

        $debug->maybeTraceIp($runtime, $ip);
        $debug->maybeStopAtIp($runtime, $ip, $this->prevInstructionPointer, $this->lastInstruction, $this->lastOpcodes);
        $debug->maybeStopOnRspBelow($runtime, $ip, $this->prevInstructionPointer);
        $debug->recordExecution($runtime, $ip);

        // Use decode cache when available
        if (isset($this->decodeCache[$ip])) {
            [$instruction, $opcodes, $length] = $this->decodeCache[$ip];
            $memory->setOffset($ip + $length);
        } else {
            // Full decode path
            $memoryAccessor->setInstructionFetch(true);

            $startPos = $ip;
            $maxOpcodeLength = $runtime->architectureProvider()->instructionList()->getMaxOpcodeLength();
            $instructionList = $runtime->architectureProvider()->instructionList();

            // NOTE: x86 allows redundant/repeated legacy prefixes. Our opcode table max length (e.g. 6 bytes)
            // can be shorter than a prefix run, so we may need to peek beyond maxOpcodeLength to reach the
            // actual opcode. Cap at 15 bytes (architectural maximum instruction length).
            $peekBytes = [];
            try {
                for ($i = 0; $i < $maxOpcodeLength && !$memory->isEOF(); $i++) {
                    $peekBytes[] = $memory->byte();
                }

                $instruction = null;
                $lastException = null;
                $length = 0;

                $canExtend = isset($peekBytes[0]) && $this->isLegacyPrefixByte($peekBytes[0]);
                while (true) {
                    [$instruction, $length, $lastException] = $this->tryFindInstructionFromPeekBytes(
                        $instructionList,
                        $peekBytes
                    );

                    if ($instruction !== null) {
                        break;
                    }

                    if (!$canExtend || count($peekBytes) >= 15 || $memory->isEOF()) {
                        if ($lastException !== null) {
                            $cs = $memoryAccessor->fetch(RegisterType::CS)->asByte() & 0xFFFF;
                            $bytesStr = implode(' ', array_map(
                                static fn (int $b): string => sprintf('%02X', $b & 0xFF),
                                $peekBytes
                            ));
                            $runtime->option()->logger()->error(sprintf(
                                'Decode failed at CS:IP=%04X:%08X bytes=%s len=%d last=%s',
                                $cs,
                                $startPos & 0xFFFFFFFF,
                                $bytesStr,
                                count($peekBytes),
                                $lastException->getMessage()
                            ));
                            throw new FaultException(0x06, 0, $lastException->getMessage());
                        }
                        throw new FaultException(0x06, 0, 'UD: decode failed');
                    }

                    // Extend peek window to include opcode after an unusually long legacy-prefix run.
                    $peekBytes[] = $memory->byte();
                }

                $opcodes = array_slice($peekBytes, 0, $length);
                $memory->setOffset($startPos + $length);

                // Cache the decode result
                $this->decodeCache[$ip] = [$instruction, $opcodes, $length];
            } catch (FaultException $e) {
                return $this->handleFault($runtime, $e, $memory->offset(), $startPos, $peekBytes);
            } finally {
                $memoryAccessor->setInstructionFetch(false);
            }
        }

        $this->lastOpcodes = $opcodes;
        $this->lastInstruction = $instruction;

        $this->maybeStopOnZeroOpcodeLoop($debug, $opcodes, $this->lastInstructionPointer);

        if ($debug->shouldTraceExecution($runtime)) {
            $debug->logExecution($runtime, $ip, $opcodes);
        }

        $status = $this->executeInstruction($runtime, $instruction, $opcodes);
        // Clear transient prefix state after executing a real instruction.
        // Prefix-only instructions (REX/REP) return CONTINUE and must keep the state for the next instruction.
        if ($status !== ExecutionStatus::CONTINUE) {
            $runtime->context()->cpu()->clearTransientOverrides();
        }
        $this->maybeTraceControlFlow($runtime, $ip, $instruction, $opcodes);
        return $status;
    }

    /**
     * Execute a Translation Block with chaining support.
     */
    private function executeBlock(RuntimeInterface $runtime, TranslationBlock $block): ExecutionStatus
    {
        $maxChainDepth = 16;
        $chainDepth = 0;

        $cflowIpBefore = 0;
        $cflowInstruction = null;
        $cflowOpcodes = null;

        $debug = $this->debug($runtime);

        $beforeInstruction = function (int $ipBefore, InstructionInterface $instruction, array $opcodes) use ($runtime, $debug, &$cflowIpBefore, &$cflowInstruction, &$cflowOpcodes): void {
            $runtime->context()->cpu()->syncCompatibilityModeWithCs();
            $debug->maybeTraceIp($runtime, $ipBefore);
            $debug->maybeStopAtIp($runtime, $ipBefore, $this->prevInstructionPointer, $this->lastInstruction, $this->lastOpcodes);
            $debug->maybeStopOnRspBelow($runtime, $ipBefore, $this->prevInstructionPointer);
            $debug->recordExecution($runtime, $ipBefore);
            $this->lastInstructionPointer = $ipBefore;
            $this->lastInstruction = $instruction;
            $this->lastOpcodes = $opcodes;
            $this->executedPages[($ipBefore & 0xFFFFFFFF) >> 12] = true;

            // Stash for post-execution control-flow tracing.
            $cflowIpBefore = $ipBefore;
            $cflowInstruction = $instruction;
            $cflowOpcodes = $opcodes;

            $this->maybeStopOnZeroOpcodeLoop($debug, $opcodes, $this->lastInstructionPointer);

            if ($debug->shouldTraceExecution($runtime)) {
                $debug->logExecution($runtime, $ipBefore, $opcodes);
            }
        };

        $instructionRunner = function (InstructionInterface $instruction, array $opcodes) use ($runtime, &$cflowIpBefore, &$cflowInstruction, &$cflowOpcodes): ExecutionStatus {
            $status = $this->executeInstruction($runtime, $instruction, $opcodes);
            if ($cflowInstruction !== null && $cflowOpcodes !== null) {
                $this->maybeTraceControlFlow($runtime, $cflowIpBefore, $cflowInstruction, $cflowOpcodes);
            }
            return $status;
        };

        while ($block !== null && $chainDepth < $maxChainDepth) {
            $startIp = $block->startIp();
            $hits = ($this->hitCount[$startIp] ?? 0) + 1;
            $this->hitCount[$startIp] = $hits;

            if ($hits === self::KERNEL_DECOMPRESS_HIT_THRESHOLD) {
                $env = UEFIRuntimeRegistry::environment($runtime);
                if ($env !== null && $env->maybeFastDecompressKernel($runtime, $startIp, $hits)) {
                    return ExecutionStatus::SUCCESS;
                }
            }

            $probeStatus = $this->maybeProbeKernelDecompress($runtime, $startIp);
            if ($probeStatus !== null) {
                return $probeStatus;
            }

            [$status, $exitIp] = $block->execute($runtime, $beforeInstruction, $instructionRunner);

            if ($status !== ExecutionStatus::SUCCESS) {
                return $status;
            }

            // Allow hot patterns to override TB chaining (important for CALL-heavy loops).
            // When a TB ends at a hot call target, chaining would otherwise bypass the pattern engine.
            $debug->maybeTraceIp($runtime, $exitIp);
            $debug->maybeStopAtIp($runtime, $exitIp, $this->prevInstructionPointer, $this->lastInstruction, $this->lastOpcodes);
            $patternResult = $this->patternedInstructionsList->tryExecutePattern($runtime, $exitIp);
            if ($patternResult !== null && $patternResult->isSuccess()) {
                $debug->recordExecution($runtime, $exitIp);
                $debug->maybeTraceControlFlowTarget($runtime, $exitIp, $patternResult->ip(), 'pattern');
                $debug->maybeStopOnControlFlowTarget($runtime, $exitIp, $patternResult->ip(), null, null, 'pattern');
                $status = $patternResult->executionStatus();
                if ($status !== ExecutionStatus::SUCCESS) {
                    return $status;
                }
                $exitIp = $patternResult->ip();
            }

            // Try to chain to next block
            $nextBlock = $block->getChainedBlock($exitIp);
            if ($nextBlock === null) {
                // Build missing block at exit IP on demand
                if (!isset($this->translationBlocks[$exitIp])) {
                    $built = $this->buildTranslationBlock($runtime, $exitIp);
                    if ($built !== null) {
                        $this->translationBlocks[$exitIp] = $built;
                    }
                }

                if (isset($this->translationBlocks[$exitIp])) {
                    $candidateBlock = $this->translationBlocks[$exitIp];

                    // Avoid chaining to the same block to prevent tight self-cycles inside chaining loop
                    if ($candidateBlock !== $block) {
                        $nextBlock = $candidateBlock;
                        $block->chainTo($exitIp, $nextBlock);
                    }
                }
            }

            if ($nextBlock === null) {
                return $status;
            }

            // Execute tick processing between chained blocks
            $runtime->tickerRegistry()->tick($runtime);
            $runtime->interruptDeliveryHandler()->deliverPendingInterrupts($runtime);
            $runtime->context()->screen()->flushIfNeeded();
            // If an interrupt changed the IP, stop chaining so execution resumes at the handler.
            if ($runtime->memory()->offset() !== $exitIp) {
                return $status;
            }

            $block = $nextBlock;
            $chainDepth++;
        }

        return ExecutionStatus::SUCCESS;
    }

    private function maybeTraceControlFlow(RuntimeInterface $runtime, int $ipBefore, InstructionInterface $instruction, array $opcodes): void
    {
        if (!$this->isControlFlowInstruction($opcodes)) {
            return;
        }

        $ipAfter = $runtime->memory()->offset();
        $debug = $this->debug($runtime);
        $debug->maybeStopOnControlFlowTarget($runtime, $ipBefore, $ipAfter, $instruction, $opcodes, 'instruction');
        $opcodeStr = implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $opcodes));
        $mnemonic = preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($instruction)) ?? 'insn';

        $debug->maybeTraceControlFlowTarget($runtime, $ipBefore, $ipAfter, $mnemonic, $opcodeStr);
    }

    private function maybeStopOnZeroOpcodeLoop(InstructionExecutorDebug $debug, array $opcodes, int $ip): void
    {
        $limit = $debug->zeroOpcodeLoopLimit();
        if ($limit <= 0) {
            $this->zeroOpcodeCount = 0;
            return;
        }

        if ($opcodes === [0x00]) {
            $this->zeroOpcodeCount++;
            if ($this->zeroOpcodeCount >= $limit) {
                throw new ExecutionException(sprintf(
                    'Infinite loop detected: %d consecutive 0x00 opcodes at IP=0x%05X',
                    $limit,
                    $ip
                ));
            }
        } else {
            $this->zeroOpcodeCount = 0;
        }
    }

    /**
     * Build a Translation Block starting from the given IP.
     * Collects instructions until a control-flow instruction (jump/call/ret).
     */
    private function buildTranslationBlock(RuntimeInterface $runtime, int $startIp): ?TranslationBlock
    {
        $memory = $runtime->memory();
        $memoryAccessor = $runtime->memoryAccessor();
        $instructionList = $runtime->architectureProvider()->instructionList();
        $maxOpcodeLength = $instructionList->getMaxOpcodeLength();

        $instructions = [];
        $totalLength = 0;
        $maxBlockSize = 32; // Max instructions per block

        // Save current memory position
        $savedPos = $memory->offset();
        $memory->setOffset($startIp);

        $memoryAccessor->setInstructionFetch(true);

        try {
            for ($i = 0; $i < $maxBlockSize; $i++) {
                $instrIp = $memory->offset();

                // Check decode cache first
                if (isset($this->decodeCache[$instrIp])) {
                    [$instruction, $opcodes, $length] = $this->decodeCache[$instrIp];
                    $memory->setOffset($instrIp + $length);
                } else {
                    // Decode instruction
                    $peekBytes = [];
                    $peekStart = $memory->offset();
                    for ($j = 0; $j < $maxOpcodeLength && !$memory->isEOF(); $j++) {
                        $peekBytes[] = $memory->byte();
                    }

                    $instruction = null;
                    $length = 0;

                    $canExtend = isset($peekBytes[0]) && $this->isLegacyPrefixByte($peekBytes[0]);
                    while (true) {
                        [$instruction, $length] = $this->tryFindInstructionFromPeekBytes($instructionList, $peekBytes);
                        if ($instruction !== null) {
                            break;
                        }

                        if (!$canExtend || count($peekBytes) >= 15 || $memory->isEOF()) {
                            break;
                        }

                        $peekBytes[] = $memory->byte();
                    }

                    if ($instruction === null) {
                        break; // Can't decode, stop block building
                    }

                    $opcodes = array_slice($peekBytes, 0, $length);
                    $memory->setOffset($peekStart + $length);

                    // Cache it
                    $this->decodeCache[$instrIp] = [$instruction, $opcodes, $length];
                }

                $instructions[] = [$instruction, $opcodes, $length];
                $totalLength += $length;

                // Stop at control-flow instructions
                if ($this->isControlFlowInstruction($opcodes)) {
                    break;
                }
            }
        } catch (FaultException $e) {
            $memory->setOffset($savedPos);
            return null;
        } finally {
            $memoryAccessor->setInstructionFetch(false);
        }

        // Restore memory position
        $memory->setOffset($savedPos);

        if (count($instructions) < 2) {
            return null; // Not worth creating a block for single instruction
        }

        return new TranslationBlock($startIp, $instructions, $totalLength);
    }

    /**
     * Check if opcodes represent a control-flow instruction.
     */
    private function isControlFlowInstruction(array $opcodes): bool
    {
        if (empty($opcodes)) {
            return false;
        }

        $i = 0;
        $count = count($opcodes);
        while ($i < $count && $this->isLegacyPrefixByte($opcodes[$i])) {
            $i++;
        }
        if ($i >= $count) {
            return false;
        }

        $op = $opcodes[$i];

        // Jumps, calls, returns, interrupts
        return match (true) {
            // REP/REPNE prefixes - treat as control flow because they return CONTINUE
            // and require special handling in the main loop
            $op === 0xF2 || $op === 0xF3 => true,
            // Short jumps (0x70-0x7F: Jcc, 0xEB: JMP short)
            $op >= 0x70 && $op <= 0x7F => true,
            $op === 0xEB => true,
            // Near jumps/calls (0xE8: CALL, 0xE9: JMP near, 0xE0-0xE3: LOOPx/JCXZ)
            $op >= 0xE0 && $op <= 0xE3 => true,
            $op === 0xE8 || $op === 0xE9 => true,
            // Far jumps/calls (0x9A: CALL far, 0xEA: JMP far)
            $op === 0x9A || $op === 0xEA => true,
            // Returns (0xC2, 0xC3: RET, 0xCA, 0xCB: RETF)
            $op === 0xC2 || $op === 0xC3 || $op === 0xCA || $op === 0xCB => true,
            // Interrupts (0xCC: INT3, 0xCD: INT, 0xCE: INTO, 0xCF: IRET)
            $op >= 0xCC && $op <= 0xCF => true,
            // 0x0F prefix (two-byte opcodes: Jcc near, etc.)
            $op === 0x0F && isset($opcodes[$i + 1]) => $this->isTwoByteControlFlow($opcodes[$i + 1]),
            // Group 5 (0xFF) - INC/DEC/CALL/JMP indirect
            $op === 0xFF => true,
            default => false,
        };
    }

    private function isLegacyPrefixByte(int $byte): bool
    {
        // Legacy x86 prefixes that are embedded in opcode patterns via InstructionPrefixApplyable.
        return in_array($byte & 0xFF, [0x66, 0x67, 0xF0, 0x26, 0x2E, 0x36, 0x3E, 0x64, 0x65], true);
    }

    /**
     * Try to find an instruction by matching longest to shortest prefix/opcode pattern.
     *
     * @param array<int> $peekBytes
     * @return array{0: ?InstructionInterface, 1: int, 2?: ?NotFoundInstructionException}
     */
    private function tryFindInstructionFromPeekBytes(
        InstructionListInterface $instructionList,
        array $peekBytes,
    ): array {
        $instruction = null;
        $lastException = null;
        $length = 0;

        for ($len = count($peekBytes); $len >= 1; $len--) {
            $tryBytes = array_slice($peekBytes, 0, $len);
            try {
                $instruction = $instructionList->findInstruction($tryBytes);
                $length = $len;
                break;
            } catch (NotFoundInstructionException $e) {
                $lastException = $e;
            }
        }

        return [$instruction, $length, $lastException];
    }

    /**
     * Check if two-byte opcode (0x0F xx) is control flow.
     */
    private function isTwoByteControlFlow(int $secondByte): bool
    {
        // 0x0F 0x80-0x8F: Jcc near
        return $secondByte >= 0x80 && $secondByte <= 0x8F;
    }

    /**
     * Execute a single instruction with fault handling.
     */
    private function executeInstruction(RuntimeInterface $runtime, InstructionInterface $instruction, array $opcodes): ExecutionStatus
    {
        try {
            return $instruction->process($runtime, $opcodes);
        } catch (FaultException $e) {
            $ip = $runtime->memory()->offset();
            $faultIp = $ip;
            $opcodeLen = count($opcodes);
            if ($opcodeLen > 0) {
                $faultIp = ($ip - $opcodeLen) & 0xFFFFFFFF;
            }
            return $this->handleFault($runtime, $e, $ip, $faultIp, $opcodes);
        } catch (ExecutionException $e) {
            $runtime->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
            throw $e;
        }
    }

    private function handleFault(RuntimeInterface $runtime, FaultException $e, int $currentIp, int $faultIp, array $opcodes): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $ma = $runtime->memoryAccessor();
        $env = UEFIRuntimeRegistry::environment($runtime);
        if ($env !== null && $env->maybeRecoverKernelJump($runtime, $currentIp, $e->vector())) {
            return ExecutionStatus::SUCCESS;
        }
        $cs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $cr2 = $ma->readControlRegister(2);

        $bytes = implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $opcodes));
        $runtime->option()->logger()->error(sprintf(
            'CPU fault: %s vec=0x%02X err=%s ip=0x%08X rip=0x%016X cs=0x%04X cr2=0x%016X PM=%d PG=%d LM=%d bytes=%s',
            $e->getMessage(),
            $e->vector() & 0xFF,
            $e->errorCode() === null ? 'n/a' : sprintf('0x%04X', $e->errorCode() & 0xFFFF),
            $currentIp & 0xFFFFFFFF,
            $currentIp,
            $cs,
            $cr2,
            $cpu->isProtectedMode() ? 1 : 0,
            $cpu->isPagingEnabled() ? 1 : 0,
            $cpu->isLongMode() ? 1 : 0,
            $bytes,
        ));
        $this->debug($runtime)->maybeDumpPageFaultContext($runtime, $e, $currentIp);
        if (
            $runtime->interruptDeliveryHandler()->raiseFault(
                $runtime,
                $e->vector(),
                $faultIp,
                $e->errorCode()
            )
        ) {
            return ExecutionStatus::SUCCESS;
        }
        throw $e;
    }

    public function lastInstruction(): ?InstructionInterface
    {
        return $this->lastInstruction;
    }

    public function lastOpcodes(): ?array
    {
        return $this->lastOpcodes;
    }

    public function lastInstructionPointer(): int
    {
        return $this->lastInstructionPointer;
    }

    /**
     * Get statistics about caching and hotspot detection.
     *
     * @return array{decode_cache_size: int, translation_blocks: int, total_block_instructions: int, total_chains: int}
     */
    public function getStats(): array
    {
        $totalBlockInstructions = 0;
        $totalChains = 0;
        foreach ($this->translationBlocks as $block) {
            $totalBlockInstructions += $block->count();
            $totalChains += $block->chainCount();
        }

        return [
            'decode_cache_size' => count($this->decodeCache),
            'translation_blocks' => count($this->translationBlocks),
            'total_block_instructions' => $totalBlockInstructions,
            'total_chains' => $totalChains,
        ];
    }

    /**
     * Get total executed instruction count (when `PHPME_COUNT_INSNS` or IP sampling is enabled).
     */
    public function instructionCount(): int
    {
        return $this->debug?->instructionCount() ?? 0;
    }

    /**
     * Get IP sampling report.
     *
     * @return array{every:int,instructions:int,samples:int,unique:int,top:array<int,array{int,int}>}
     */
    public function getIpSampleReport(int $top = 20): array
    {
        if ($this->debug === null) {
            return [
                'every' => 0,
                'instructions' => 0,
                'samples' => 0,
                'unique' => 0,
                'top' => [],
            ];
        }

        return $this->debug->getIpSampleReport($top);
    }

    /**
     * Invalidate decode/translation caches (e.g., on CR0 mode switch).
     */
    public function invalidateCaches(): void
    {
        $this->decodeCache = [];
        $this->hitCount = [];
        $this->translationBlocks = [];
        $this->debug?->resetTraceCache();
        $this->patternedInstructionsList?->invalidateCaches();
    }

    /**
     * Best-effort cache invalidation when code is written into an already-executed page.
     *
     * This protects against stale decode/translation caches when boot loaders relocate
     * or load modules into memory regions that previously contained executed code.
     */
    public function invalidateCachesIfExecutedPageOverlaps(int $start, int $length): void
    {
        if ($length <= 0 || $this->executedPages === []) {
            return;
        }

        $s = $start & 0xFFFFFFFF;
        $e = ($s + ($length - 1)) & 0xFFFFFFFF;
        $startPage = $s >> 12;
        $endPage = $e >> 12;

        // Handle wrap-around conservatively.
        if ($endPage < $startPage) {
            $this->invalidateCaches();
            return;
        }

        for ($page = $startPage; $page <= $endPage; $page++) {
            if (isset($this->executedPages[$page])) {
                $this->invalidateCaches();
                return;
            }
        }
    }

    /**
     * Get hot pattern detector statistics.
     */
    public function getHotPatternStats(): PatternedInstructionsListStats
    {
        return $this->patternedInstructionsList->getStats();
    }

    /**
     * Get patterned instructions list.
     */
    public function patternedInstructionsList(): PatternedInstructionsList
    {
        return $this->patternedInstructionsList;
    }
}
