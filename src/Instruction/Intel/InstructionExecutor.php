<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\NotFoundInstructionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\Intel\TranslationBlock;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\PatternedInstructionsList;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\PatternedInstructionsListStats;

class InstructionExecutor implements InstructionExecutorInterface
{
    private ?InstructionInterface $lastInstruction = null;
    private ?array $lastOpcodes = null;
    private int $lastInstructionPointer = 0;
    private int $zeroOpcodeCount = 0;

    /**
     * Instruction decode cache: IP => [instruction, opcodes, length]
     * @var array<int, array{InstructionInterface, array<int>, int}>
     */
    private array $decodeCache = [];

    /**
     * Execution hit count per IP for hotspot detection
     * @var array<int, int>
     */
    private array $hitCount = [];

    /**
     * Translation Blocks: startIP => TranslationBlock
     * @var array<int, TranslationBlock>
     */
    private array $translationBlocks = [];

    /**
     * Patterned instructions list for optimizing frequently-executed code patterns
     */
    private PatternedInstructionsList $patternedInstructionsList;

    private const HOTSPOT_THRESHOLD = 1;

    public function __construct()
    {
        $this->patternedInstructionsList = new PatternedInstructionsList();
    }

    public function execute(RuntimeInterface $runtime): ExecutionStatus
    {
        $ip = $runtime->memory()->offset();
        $this->lastInstructionPointer = $ip;

        // REP/iteration handler active? Fall back to single-step to keep lastInstruction accurate.
        if ($runtime->context()->cpu()->iteration()->isActive()) {
            return $this->executeSingleInstruction($runtime, $ip);
        }

        // Try hot pattern detection first (fastest path for known patterns)
        $patternResult = $this->patternedInstructionsList->tryExecutePattern($runtime, $ip);
        if ($patternResult !== null && $patternResult->isSuccess()) {
            return $patternResult->executionStatus();
        }

        // Execute existing translation block if present
        if (isset($this->translationBlocks[$ip])) {
            return $this->executeBlock($runtime, $this->translationBlocks[$ip]);
        }

        // Hotspot detection: count hits per IP
        $hits = ($this->hitCount[$ip] ?? 0) + 1;
        $this->hitCount[$ip] = $hits;

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

    /**
     * Execute a single instruction, using decode cache if available.
     */
    private function executeSingleInstruction(RuntimeInterface $runtime, int $ip): ExecutionStatus
    {
        $memory = $runtime->memory();
        $memoryAccessor = $runtime->memoryAccessor();

        // Use decode cache when available
        if (isset($this->decodeCache[$ip])) {
            [$instruction, $opcodes, $length] = $this->decodeCache[$ip];
            $memory->setOffset($ip + $length);
        } else {
            // Full decode path
            $memoryAccessor->setInstructionFetch(true);

            $startPos = $ip;
            $maxOpcodeLength = $runtime->architectureProvider()->instructionList()->getMaxOpcodeLength();
            $peekBytes = [];
            for ($i = 0; $i < $maxOpcodeLength && !$memory->isEOF(); $i++) {
                $peekBytes[] = $memory->byte();
            }

            // Try to find instruction from longest to shortest pattern
            $instruction = null;
            $lastException = null;
            $length = 0;
            for ($len = count($peekBytes); $len >= 1; $len--) {
                $tryBytes = array_slice($peekBytes, 0, $len);
                try {
                    $instruction = $runtime->architectureProvider()->instructionList()->findInstruction($tryBytes);
                    $length = $len;
                    break;
                } catch (NotFoundInstructionException $e) {
                    $lastException = $e;
                    continue;
                }
            }

            if ($instruction === null && $lastException !== null) {
                throw $lastException;
            }

            $opcodes = array_slice($peekBytes, 0, $length);
            $memoryAccessor->setInstructionFetch(false);
            $memory->setOffset($startPos + $length);

            // Cache the decode result
            $this->decodeCache[$ip] = [$instruction, $opcodes, $length];
        }

        $this->lastOpcodes = $opcodes;
        $this->lastInstruction = $instruction;

        // Detect infinite loop
        if ($opcodes === [0x00]) {
            $this->zeroOpcodeCount++;
            if ($this->zeroOpcodeCount >= 255) {
                throw new ExecutionException(sprintf(
                    'Infinite loop detected: 255 consecutive 0x00 opcodes at IP=0x%05X',
                    $this->lastInstructionPointer
                ));
            }
        } else {
            $this->zeroOpcodeCount = 0;
        }

        $this->logExecution($runtime, $ip, $opcodes);

        return $this->executeInstruction($runtime, $instruction, $opcodes);
    }

    /**
     * Execute a Translation Block with chaining support.
     */
    private function executeBlock(RuntimeInterface $runtime, TranslationBlock $block): ExecutionStatus
    {
        $maxChainDepth = 16;
        $chainDepth = 0;

        $beforeInstruction = function (int $ipBefore, InstructionInterface $instruction, array $opcodes) use ($runtime): void {
            $this->lastInstructionPointer = $ipBefore;
            $this->lastInstruction = $instruction;
            $this->lastOpcodes = $opcodes;

            if ($opcodes === [0x00]) {
                $this->zeroOpcodeCount++;
                if ($this->zeroOpcodeCount >= 255) {
                    throw new ExecutionException(sprintf(
                        'Infinite loop detected: 255 consecutive 0x00 opcodes at IP=0x%05X',
                        $this->lastInstructionPointer
                    ));
                }
            } else {
                $this->zeroOpcodeCount = 0;
            }

            $this->logExecution($runtime, $ipBefore, $opcodes);
        };

        $instructionRunner = function (InstructionInterface $instruction, array $opcodes) use ($runtime): ExecutionStatus {
            return $this->executeInstruction($runtime, $instruction, $opcodes);
        };

        while ($block !== null && $chainDepth < $maxChainDepth) {
            [$status, $exitIp] = $block->execute($runtime, $beforeInstruction, $instructionRunner);

            if ($status !== ExecutionStatus::SUCCESS) {
                return $status;
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


                    $runtime->option()->logger()->info(
                        "PATTERN (OP): " . implode(" ", array_map(
                            fn ($ins) => implode(" ", array_map(fn ($v) => sprintf("0x%02X", $v), $ins[1])),
                            $candidateBlock->instructions(),
                        )),
                    );

                    $runtime->option()->logger()->info(
                        "PATTERN (MN): " . implode(" ", array_map(
                            fn ($ins) => preg_replace("/^.+\\\\(.+?)$/", '$1', get_class($ins[0])),
                            $candidateBlock->instructions(),
                        )),
                    );

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

            $block = $nextBlock;
            $chainDepth++;
        }

        return ExecutionStatus::SUCCESS;
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
                for ($len = count($peekBytes); $len >= 1; $len--) {
                    $tryBytes = array_slice($peekBytes, 0, $len);
                    try {
                        $instruction = $instructionList->findInstruction($tryBytes);
                        $length = $len;
                        break;
                    } catch (NotFoundInstructionException) {
                        continue;
                    }
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

        $memoryAccessor->setInstructionFetch(false);

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

        $op = $opcodes[0];

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
            $op === 0x0F && isset($opcodes[1]) => $this->isTwoByteControlFlow($opcodes[1]),
            // Group 5 (0xFF) - INC/DEC/CALL/JMP indirect
            $op === 0xFF => true,
            default => false,
        };
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
            $runtime->option()->logger()->error(sprintf('CPU fault: %s', $e->getMessage()));
            if ($runtime->interruptDeliveryHandler()->raiseFault(
                $runtime,
                $e->vector(),
                $runtime->memory()->offset(),
                $e->errorCode()
            )) {
                return ExecutionStatus::SUCCESS;
            }
            throw $e;
        } catch (ExecutionException $e) {
            $runtime->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
            throw $e;
        }
    }

    private function logExecution(RuntimeInterface $runtime, int $ipBefore, array $opcodes): void
    {
        $memoryAccessor = $runtime->memoryAccessor();
        $cf = $memoryAccessor->shouldCarryFlag() ? 1 : 0;
        $zf = $memoryAccessor->shouldZeroFlag() ? 1 : 0;
        $sf = $memoryAccessor->shouldSignFlag() ? 1 : 0;
        $of = $memoryAccessor->shouldOverflowFlag() ? 1 : 0;
        $eax = $memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32);
        $ebx = $memoryAccessor->fetch(RegisterType::EBX)->asBytesBySize(32);
        $ecx = $memoryAccessor->fetch(RegisterType::ECX)->asBytesBySize(32);
        $edx = $memoryAccessor->fetch(RegisterType::EDX)->asBytesBySize(32);
        $esi = $memoryAccessor->fetch(RegisterType::ESI)->asBytesBySize(32);
        $edi = $memoryAccessor->fetch(RegisterType::EDI)->asBytesBySize(32);
        $ebp = $memoryAccessor->fetch(RegisterType::EBP)->asBytesBySize(32);
        $esp = $memoryAccessor->fetch(RegisterType::ESP)->asBytesBySize(32);
        $opcodeStr = implode(' ', array_map(fn($b) => sprintf('0x%02X', $b), $opcodes));
        $runtime->option()->logger()->debug(sprintf(
            'EXEC: IP=0x%05X op=%-12s FL[CF=%d ZF=%d SF=%d OF=%d] EAX=%08X EBX=%08X ECX=%08X EDX=%08X ESI=%08X EDI=%08X EBP=%08X ESP=%08X',
            $ipBefore, $opcodeStr, $cf, $zf, $sf, $of, $eax, $ebx, $ecx, $edx, $esi, $edi, $ebp, $esp
        ));
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
     * Invalidate decode/translation caches (e.g., on CR0 mode switch).
     */
    public function invalidateCaches(): void
    {
        $this->decodeCache = [];
        $this->hitCount = [];
        $this->translationBlocks = [];
        $this->patternedInstructionsList->invalidateCaches();
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
