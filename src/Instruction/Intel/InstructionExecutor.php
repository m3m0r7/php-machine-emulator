<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
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
     * Pages that have been executed (for best-effort self-modifying code handling).
     * @var array<int,true>
     */
    private array $executedPages = [];

    /**
     * Execution hit count per IP for hotspot detection
     * @var array<int, int>
     */
    private array $hitCount = [];

    /**
     * Cached decision for per-instruction execution tracing.
     */
    private ?bool $traceExecution = null;

    /**
     * Optional executed instruction counter (for profiling/debugging).
     */
    private int $executedInstructions = 0;

    /**
     * Enable executed-instruction counting (env-gated).
     */
    private ?bool $countInstructionsEnabled = null;

    /**
     * IP sampling interval (in guest instructions). 0 disables sampling.
     */
    private ?int $ipSampleEvery = null;

    /**
     * Countdown to next sample (avoids modulus on hot path).
     */
    private int $ipSampleCountdown = 0;

    /**
     * Sampled IP hit counts (only when sampling enabled).
     *
     * @var array<int,int>
     */
    private array $ipSampleHits = [];

    /**
     * Optional stop-after instruction count (env-gated).
     */
    private ?int $stopAfterInsns = null;

    /**
     * Remaining instructions until stop (hot path decrement).
     */
    private int $stopAfterInsnsRemaining = 0;

    /**
     * Optional stop-after seconds (env-gated).
     */
    private ?int $stopAfterSecs = null;

    /**
     * Deadline timestamp (microtime(true)) for stop-after seconds.
     */
    private ?float $stopAfterDeadline = null;

    /**
     * Check wall-clock time only every N instructions to keep overhead low.
     */
    private int $stopAfterTimeEvery = 10000;

    /**
     * Countdown to the next wall-clock time check.
     */
    private int $stopAfterTimeCountdown = 0;

    /**
     * Optional IP tracing set (env-gated).
     *
     * @var array<int,true>|null
     */
    private ?array $traceIpSet = null;

    /**
     * Optional stop-at-IP set (env-gated).
     *
     * @var array<int,true>|null
     */
    private ?array $stopIpSet = null;

    /**
     * Max logs per traced IP.
     */
    private ?int $traceIpLimit = null;

    /**
     * Logged counts per traced IP.
     *
     * @var array<int,int>
     */
    private array $traceIpCounts = [];

    /**
     * Optional control-flow target tracing set (env-gated).
     *
     * @var array<int,true>|null
     */
    private ?array $traceCflowToSet = null;

    /**
     * Optional stop-on-control-flow target set (env-gated).
     *
     * @var array<int,true>|null
     */
    private ?array $stopCflowToSet = null;

    /**
     * Logged counts per traced control-flow target IP.
     *
     * @var array<int,int>
     */
    private array $traceCflowCounts = [];

    /**
     * Max logs per traced control-flow target.
     */
    private ?int $traceCflowLimit = null;

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
        $this->executedPages[($ip & 0xFFFFFFFF) >> 12] = true;
        $this->maybeStopAtIp($runtime, $ip);

        // REP/iteration handler active? Fall back to single-step to keep lastInstruction accurate.
        if ($runtime->context()->cpu()->iteration()->isActive()) {
            return $this->executeSingleInstruction($runtime, $ip);
        }

        // Try hot pattern detection first (fastest path for known patterns)
        $patternResult = $this->patternedInstructionsList->tryExecutePattern($runtime, $ip);
        if ($patternResult !== null && $patternResult->isSuccess()) {
            $this->recordExecution($runtime, $ip);
            $this->maybeTraceControlFlowTarget($runtime, $ip, $patternResult->ip(), 'pattern');
            $this->maybeStopOnControlFlowTarget($runtime, $ip, $patternResult->ip(), null, null, 'pattern');
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

    private function countInstructionsEnabled(): bool
    {
        if ($this->countInstructionsEnabled !== null) {
            return $this->countInstructionsEnabled;
        }

        $env = getenv('PHPME_COUNT_INSNS');
        $explicit = $env !== false && trim($env) !== '' && trim($env) !== '0';
        $this->countInstructionsEnabled = $explicit || ($this->ipSampleEvery() > 0);
        return $this->countInstructionsEnabled;
    }

    private function ipSampleEvery(): int
    {
        if ($this->ipSampleEvery !== null) {
            return $this->ipSampleEvery;
        }

        $env = getenv('PHPME_IP_SAMPLE_EVERY');
        if ($env === false) {
            $this->ipSampleEvery = 0;
            return 0;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->ipSampleEvery = 0;
            return 0;
        }

        if ($trimmed === '1') {
            // A small but sane default when enabled.
            $this->ipSampleEvery = 10000;
            return $this->ipSampleEvery;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            $this->ipSampleEvery = max(1, (int) $trimmed);
            return $this->ipSampleEvery;
        }

        $this->ipSampleEvery = 0;
        return 0;
    }

    private function stopAfterInsns(): int
    {
        if ($this->stopAfterInsns !== null) {
            return $this->stopAfterInsns;
        }

        $env = getenv('PHPME_STOP_AFTER_INSNS');
        if ($env === false) {
            $this->stopAfterInsns = 0;
            return 0;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->stopAfterInsns = 0;
            return 0;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            $this->stopAfterInsns = max(1, (int) $trimmed);
            $this->stopAfterInsnsRemaining = $this->stopAfterInsns;
            return $this->stopAfterInsns;
        }

        $this->stopAfterInsns = 0;
        return 0;
    }

    private function stopAfterSecs(): int
    {
        if ($this->stopAfterSecs !== null) {
            return $this->stopAfterSecs;
        }

        $env = getenv('PHPME_STOP_AFTER_SECS');
        if ($env === false) {
            $this->stopAfterSecs = 0;
            return 0;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->stopAfterSecs = 0;
            return 0;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            $this->stopAfterSecs = max(1, (int) $trimmed);
            return $this->stopAfterSecs;
        }

        $this->stopAfterSecs = 0;
        return 0;
    }

    private function maybeStopAfter(RuntimeInterface $runtime): void
    {
        $logSampleReport = function () use ($runtime): void {
            $report = $this->getIpSampleReport(20);
            if (($report['every'] ?? 0) > 0 && ($report['samples'] ?? 0) > 0) {
                $runtime->option()->logger()->warning(sprintf(
                    'IP SAMPLE: every=%d insns=%d samples=%d unique=%d',
                    (int) ($report['every'] ?? 0),
                    (int) ($report['instructions'] ?? 0),
                    (int) ($report['samples'] ?? 0),
                    (int) ($report['unique'] ?? 0),
                ));
                foreach (($report['top'] ?? []) as $row) {
                    $ipVal = (int) ($row[0] ?? 0);
                    $hits = (int) ($row[1] ?? 0);
                    $runtime->option()->logger()->warning(sprintf(
                        'IP SAMPLE TOP: ip=0x%08X hits=%d',
                        $ipVal & 0xFFFFFFFF,
                        $hits,
                    ));
                }
            } else {
                $runtime->option()->logger()->warning(sprintf(
                    'INSNS: total=%d',
                    (int) $this->instructionCount(),
                ));
            }
        };

        $stopAfterInsns = $this->stopAfterInsns();
        if ($stopAfterInsns > 0) {
            $this->stopAfterInsnsRemaining--;
            if ($this->stopAfterInsnsRemaining <= 0) {
                $runtime->option()->logger()->warning(sprintf(
                    'STOP: reached PHPME_STOP_AFTER_INSNS=%d at ip=0x%08X',
                    $stopAfterInsns,
                    $runtime->memory()->offset() & 0xFFFFFFFF,
                ));
                $logSampleReport();
                throw new HaltException('Stopped by PHPME_STOP_AFTER_INSNS');
            }
        }

        $secs = $this->stopAfterSecs();
        if ($secs <= 0) {
            return;
        }

        if ($this->stopAfterDeadline === null) {
            $this->stopAfterDeadline = microtime(true) + (float) $secs;
            $everyEnv = getenv('PHPME_STOP_AFTER_TIME_EVERY');
            if ($everyEnv !== false) {
                $trimmed = trim($everyEnv);
                if ($trimmed !== '' && $trimmed !== '0' && preg_match('/^\\d+$/', $trimmed) === 1) {
                    $this->stopAfterTimeEvery = max(1, (int) $trimmed);
                }
            }
            $this->stopAfterTimeCountdown = $this->stopAfterTimeEvery;
        }

        if ($this->stopAfterTimeCountdown <= 0) {
            $this->stopAfterTimeCountdown = $this->stopAfterTimeEvery;
        }
        $this->stopAfterTimeCountdown--;
        if ($this->stopAfterTimeCountdown !== 0) {
            return;
        }

        if (microtime(true) >= ($this->stopAfterDeadline ?? 0.0)) {
            $runtime->option()->logger()->warning(sprintf(
                'STOP: reached PHPME_STOP_AFTER_SECS=%d at ip=0x%08X',
                $secs,
                $runtime->memory()->offset() & 0xFFFFFFFF,
            ));
            $logSampleReport();
            throw new HaltException('Stopped by PHPME_STOP_AFTER_SECS');
        }
    }

    private function recordExecution(RuntimeInterface $runtime, int $ip): void
    {
        $every = $this->ipSampleEvery();
        if ($every <= 0 && !$this->countInstructionsEnabled() && $this->stopAfterInsns() <= 0 && $this->stopAfterSecs() <= 0) {
            return;
        }

        $this->executedInstructions++;
        $this->maybeStopAfter($runtime);

        if ($every <= 0) {
            return;
        }

        if ($this->ipSampleCountdown <= 0) {
            $this->ipSampleCountdown = $every;
        }

        $this->ipSampleCountdown--;
        if ($this->ipSampleCountdown !== 0) {
            return;
        }

        $this->ipSampleHits[$ip] = ($this->ipSampleHits[$ip] ?? 0) + 1;
        $this->ipSampleCountdown = $every;
    }

    private function shouldTraceExecution(RuntimeInterface $runtime): bool
    {
        if ($this->traceExecution !== null) {
            return $this->traceExecution;
        }

        $env = getenv('PHPME_TRACE_EXEC');
        if ($env !== false && trim($env) !== '' && trim($env) !== '0') {
            $this->traceExecution = true;
            return true;
        }

        $logger = $runtime->option()->logger();
        if ($logger instanceof \Monolog\Logger) {
            $this->traceExecution = $logger->isHandling(\Monolog\Level::Debug);
            return $this->traceExecution;
        }

        $this->traceExecution = false;
        return false;
    }

    /**
     * Execute a single instruction, using decode cache if available.
     */
    private function executeSingleInstruction(RuntimeInterface $runtime, int $ip): ExecutionStatus
    {
        $memory = $runtime->memory();
        $memoryAccessor = $runtime->memoryAccessor();

        $this->maybeTraceIp($runtime, $ip);
        $this->recordExecution($runtime, $ip);

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
                        throw $lastException;
                    }
                    throw new NotFoundInstructionException('No found instruction (decode failed)');
                }

                // Extend peek window to include opcode after an unusually long legacy-prefix run.
                $peekBytes[] = $memory->byte();
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

        if ($this->shouldTraceExecution($runtime)) {
            $this->logExecution($runtime, $ip, $opcodes);
        }

        $status = $this->executeInstruction($runtime, $instruction, $opcodes);
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

        $beforeInstruction = function (int $ipBefore, InstructionInterface $instruction, array $opcodes) use ($runtime, &$cflowIpBefore, &$cflowInstruction, &$cflowOpcodes): void {
            $this->maybeTraceIp($runtime, $ipBefore);
            $this->maybeStopAtIp($runtime, $ipBefore);
            $this->recordExecution($runtime, $ipBefore);
            $this->lastInstructionPointer = $ipBefore;
            $this->lastInstruction = $instruction;
            $this->lastOpcodes = $opcodes;
            $this->executedPages[($ipBefore & 0xFFFFFFFF) >> 12] = true;

            // Stash for post-execution control-flow tracing.
            $cflowIpBefore = $ipBefore;
            $cflowInstruction = $instruction;
            $cflowOpcodes = $opcodes;

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

            if ($this->shouldTraceExecution($runtime)) {
                $this->logExecution($runtime, $ipBefore, $opcodes);
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
            [$status, $exitIp] = $block->execute($runtime, $beforeInstruction, $instructionRunner);

            if ($status !== ExecutionStatus::SUCCESS) {
                return $status;
            }

            // Allow hot patterns to override TB chaining (important for CALL-heavy loops).
            // When a TB ends at a hot call target, chaining would otherwise bypass the pattern engine.
            $this->maybeTraceIp($runtime, $exitIp);
            $this->maybeStopAtIp($runtime, $exitIp);
            $patternResult = $this->patternedInstructionsList->tryExecutePattern($runtime, $exitIp);
            if ($patternResult !== null && $patternResult->isSuccess()) {
                $this->recordExecution($runtime, $exitIp);
                $this->maybeTraceControlFlowTarget($runtime, $exitIp, $patternResult->ip(), 'pattern');
                $this->maybeStopOnControlFlowTarget($runtime, $exitIp, $patternResult->ip(), null, null, 'pattern');
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

            $block = $nextBlock;
            $chainDepth++;
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Trace execution at specific IPs for debugging (env-gated).
     *
     * - PHPME_TRACE_IP=0x89FA or "0x89FA,0x8A12"
     * - PHPME_TRACE_IP_LIMIT=10
     */
    private function maybeTraceIp(RuntimeInterface $runtime, int $ip): void
    {
        $set = $this->traceIpSet();
        if ($set === [] || !isset($set[$ip & 0xFFFFFFFF])) {
            return;
        }

        $limit = $this->traceIpLimit();
        $count = ($this->traceIpCounts[$ip] ?? 0);
        if ($count >= $limit) {
            return;
        }
        $this->traceIpCounts[$ip] = $count + 1;

        $cpu = $runtime->context()->cpu();
        $ma = $runtime->memoryAccessor();

        $cs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $ds = $ma->fetch(RegisterType::DS)->asByte() & 0xFFFF;
        $ss = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;

        $csCached = $cpu->getCachedSegmentDescriptor(RegisterType::CS);
        $dsCached = $cpu->getCachedSegmentDescriptor(RegisterType::DS);
        $ssCached = $cpu->getCachedSegmentDescriptor(RegisterType::SS);

        $bytes = [];
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($ip);
        for ($i = 0; $i < 16 && !$memory->isEOF(); $i++) {
            $bytes[] = $memory->byte();
        }
        $memory->setOffset($saved);

        $hex = implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $bytes));

        $runtime->option()->logger()->warning(sprintf(
            'TRACE_IP: ip=0x%08X bytes=%s PM=%d PG=%d LM=%d op=%d addr=%d A20=%d CS=0x%04X DS=0x%04X SS=0x%04X csBase=0x%08X csDef=%s dsBase=0x%08X ssBase=0x%08X',
            $ip & 0xFFFFFFFF,
            $hex,
            $cpu->isProtectedMode() ? 1 : 0,
            $cpu->isPagingEnabled() ? 1 : 0,
            $cpu->isLongMode() ? 1 : 0,
            $cpu->operandSize(),
            $cpu->addressSize(),
            $cpu->isA20Enabled() ? 1 : 0,
            $cs,
            $ds,
            $ss,
            (int) (($csCached['base'] ?? 0) & 0xFFFFFFFF),
            $csCached === null ? 'n/a' : (string) ($csCached['default'] ?? 'n/a'),
            (int) (($dsCached['base'] ?? 0) & 0xFFFFFFFF),
            (int) (($ssCached['base'] ?? 0) & 0xFFFFFFFF),
        ));
    }

    /**
     * @return array<int,true>
     */
    private function traceIpSet(): array
    {
        if ($this->traceIpSet !== null) {
            return $this->traceIpSet;
        }

        $env = getenv('PHPME_TRACE_IP');
        if ($env === false) {
            $this->traceIpSet = [];
            return $this->traceIpSet;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->traceIpSet = [];
            return $this->traceIpSet;
        }

        $parts = preg_split('/[\\s,]+/', $trimmed);
        $set = [];
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $p = trim($part);
            if ($p === '') {
                continue;
            }

            if (preg_match('/^0x[0-9a-fA-F]+$/', $p) === 1) {
                $set[(int) hexdec(substr($p, 2)) & 0xFFFFFFFF] = true;
                continue;
            }
            if (preg_match('/^\\d+$/', $p) === 1) {
                $set[(int) $p & 0xFFFFFFFF] = true;
                continue;
            }
        }

        $this->traceIpSet = $set;
        return $this->traceIpSet;
    }

    /**
     * @return array<int,true>
     */
    private function stopIpSet(): array
    {
        if ($this->stopIpSet !== null) {
            return $this->stopIpSet;
        }

        $env = getenv('PHPME_STOP_AT_IP');
        if ($env === false) {
            $this->stopIpSet = [];
            return $this->stopIpSet;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->stopIpSet = [];
            return $this->stopIpSet;
        }

        $parts = preg_split('/[\\s,]+/', $trimmed);
        $set = [];
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $p = trim($part);
            if ($p === '') {
                continue;
            }

            if (preg_match('/^0x[0-9a-fA-F]+$/', $p) === 1) {
                $set[(int) hexdec(substr($p, 2)) & 0xFFFFFFFF] = true;
                continue;
            }
            if (preg_match('/^\\d+$/', $p) === 1) {
                $set[(int) $p & 0xFFFFFFFF] = true;
                continue;
            }
        }

        $this->stopIpSet = $set;
        return $this->stopIpSet;
    }

    private function traceIpLimit(): int
    {
        if ($this->traceIpLimit !== null) {
            return $this->traceIpLimit;
        }

        $env = getenv('PHPME_TRACE_IP_LIMIT');
        if ($env === false) {
            $this->traceIpLimit = 10;
            return $this->traceIpLimit;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->traceIpLimit = 10;
            return $this->traceIpLimit;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            $this->traceIpLimit = max(1, (int) $trimmed);
            return $this->traceIpLimit;
        }

        $this->traceIpLimit = 10;
        return $this->traceIpLimit;
    }

    /**
     * @return array<int,true>
     */
    private function traceCflowToSet(): array
    {
        if ($this->traceCflowToSet !== null) {
            return $this->traceCflowToSet;
        }

        $env = getenv('PHPME_TRACE_CFLOW_TO');
        if ($env === false) {
            $this->traceCflowToSet = [];
            return $this->traceCflowToSet;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->traceCflowToSet = [];
            return $this->traceCflowToSet;
        }

        $parts = preg_split('/[\\s,]+/', $trimmed);
        $set = [];
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $p = trim($part);
            if ($p === '') {
                continue;
            }

            if (preg_match('/^0x[0-9a-fA-F]+$/', $p) === 1) {
                $set[(int) hexdec(substr($p, 2)) & 0xFFFFFFFF] = true;
                continue;
            }
            if (preg_match('/^\\d+$/', $p) === 1) {
                $set[(int) $p & 0xFFFFFFFF] = true;
                continue;
            }
        }

        $this->traceCflowToSet = $set;
        return $this->traceCflowToSet;
    }

    /**
     * @return array<int,true>
     */
    private function stopCflowToSet(): array
    {
        if ($this->stopCflowToSet !== null) {
            return $this->stopCflowToSet;
        }

        $env = getenv('PHPME_STOP_ON_CFLOW_TO');
        if ($env === false) {
            $this->stopCflowToSet = [];
            return $this->stopCflowToSet;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->stopCflowToSet = [];
            return $this->stopCflowToSet;
        }

        $parts = preg_split('/[\\s,]+/', $trimmed);
        $set = [];
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $p = trim($part);
            if ($p === '') {
                continue;
            }

            if (preg_match('/^0x[0-9a-fA-F]+$/', $p) === 1) {
                $set[(int) hexdec(substr($p, 2)) & 0xFFFFFFFF] = true;
                continue;
            }
            if (preg_match('/^\\d+$/', $p) === 1) {
                $set[(int) $p & 0xFFFFFFFF] = true;
                continue;
            }
        }

        $this->stopCflowToSet = $set;
        return $this->stopCflowToSet;
    }

    private function traceCflowLimit(): int
    {
        if ($this->traceCflowLimit !== null) {
            return $this->traceCflowLimit;
        }

        $env = getenv('PHPME_TRACE_CFLOW_LIMIT');
        if ($env === false) {
            $this->traceCflowLimit = 10;
            return $this->traceCflowLimit;
        }

        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            $this->traceCflowLimit = 10;
            return $this->traceCflowLimit;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            $this->traceCflowLimit = max(1, (int) $trimmed);
            return $this->traceCflowLimit;
        }

        $this->traceCflowLimit = 10;
        return $this->traceCflowLimit;
    }

    private function maybeTraceControlFlowTarget(
        RuntimeInterface $runtime,
        int $ipBefore,
        int $ipAfter,
        string $source,
        ?string $bytes = null,
    ): void {
        $targets = $this->traceCflowToSet();
        $maskedAfter = $ipAfter & 0xFFFFFFFF;
        if ($targets === [] || !isset($targets[$maskedAfter])) {
            return;
        }

        $limit = $this->traceCflowLimit();
        $count = ($this->traceCflowCounts[$maskedAfter] ?? 0);
        if ($count >= $limit) {
            return;
        }
        $this->traceCflowCounts[$maskedAfter] = $count + 1;

        $ma = $runtime->memoryAccessor();
        $cf = $ma->shouldCarryFlag() ? 1 : 0;
        $zf = $ma->shouldZeroFlag() ? 1 : 0;
        $sf = $ma->shouldSignFlag() ? 1 : 0;
        $of = $ma->shouldOverflowFlag() ? 1 : 0;

        if ($bytes === null) {
            $runtime->option()->logger()->warning(sprintf(
                'TRACE_CFLOW: %s ip=0x%08X -> 0x%08X FL[CF=%d ZF=%d SF=%d OF=%d]',
                $source,
                $ipBefore & 0xFFFFFFFF,
                $maskedAfter,
                $cf,
                $zf,
                $sf,
                $of,
            ));
            return;
        }

        $runtime->option()->logger()->warning(sprintf(
            'TRACE_CFLOW: %s ip=0x%08X -> 0x%08X bytes=%s FL[CF=%d ZF=%d SF=%d OF=%d]',
            $source,
            $ipBefore & 0xFFFFFFFF,
            $maskedAfter,
            $bytes,
            $cf,
            $zf,
            $sf,
            $of,
        ));
    }

    private function maybeTraceControlFlow(RuntimeInterface $runtime, int $ipBefore, InstructionInterface $instruction, array $opcodes): void
    {
        if (!$this->isControlFlowInstruction($opcodes)) {
            return;
        }

        $ipAfter = $runtime->memory()->offset();
        $this->maybeStopOnControlFlowTarget($runtime, $ipBefore, $ipAfter, $instruction, $opcodes, 'instruction');
        $opcodeStr = implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $opcodes));
        $mnemonic = preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($instruction)) ?? 'insn';

        $this->maybeTraceControlFlowTarget($runtime, $ipBefore, $ipAfter, $mnemonic, $opcodeStr);
    }

    private function maybeStopAtIp(RuntimeInterface $runtime, int $ip): void
    {
        $set = $this->stopIpSet();
        $masked = $ip & 0xFFFFFFFF;
        if ($set === [] || !isset($set[$masked])) {
            return;
        }

        $cpu = $runtime->context()->cpu();
        $ma = $runtime->memoryAccessor();

        $bytes = [];
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($ip);
        for ($i = 0; $i < 16 && !$memory->isEOF(); $i++) {
            $bytes[] = $memory->byte();
        }
        $memory->setOffset($saved);

        $hex = implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $bytes));

        $runtime->option()->logger()->warning(sprintf(
            'STOP_AT_IP: ip=0x%08X bytes=%s PM=%d PG=%d LM=%d op=%d addr=%d A20=%d CS=0x%04X',
            $masked,
            $hex,
            $cpu->isProtectedMode() ? 1 : 0,
            $cpu->isPagingEnabled() ? 1 : 0,
            $cpu->isLongMode() ? 1 : 0,
            $cpu->operandSize(),
            $cpu->addressSize(),
            $cpu->isA20Enabled() ? 1 : 0,
            $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF,
        ));

        throw new HaltException('Stopped by PHPME_STOP_AT_IP');
    }

    private function maybeStopOnControlFlowTarget(
        RuntimeInterface $runtime,
        int $ipBefore,
        int $ipAfter,
        ?InstructionInterface $instruction,
        ?array $opcodes,
        string $source,
    ): void {
        $targets = $this->stopCflowToSet();
        $maskedAfter = $ipAfter & 0xFFFFFFFF;
        if ($targets === [] || !isset($targets[$maskedAfter])) {
            return;
        }

        $ma = $runtime->memoryAccessor();
        $cf = $ma->shouldCarryFlag() ? 1 : 0;
        $zf = $ma->shouldZeroFlag() ? 1 : 0;
        $sf = $ma->shouldSignFlag() ? 1 : 0;
        $of = $ma->shouldOverflowFlag() ? 1 : 0;

        $mnemonic = $source;
        if ($instruction !== null) {
            $mnemonic = preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($instruction)) ?? $source;
        }

        $bytes = $opcodes === null
            ? null
            : implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $opcodes));

        if ($bytes === null) {
            $runtime->option()->logger()->warning(sprintf(
                'STOP_CFLOW_TO: %s ip=0x%08X -> 0x%08X FL[CF=%d ZF=%d SF=%d OF=%d]',
                $mnemonic,
                $ipBefore & 0xFFFFFFFF,
                $maskedAfter,
                $cf,
                $zf,
                $sf,
                $of,
            ));
        } else {
            $runtime->option()->logger()->warning(sprintf(
                'STOP_CFLOW_TO: %s ip=0x%08X -> 0x%08X bytes=%s FL[CF=%d ZF=%d SF=%d OF=%d]',
                $mnemonic,
                $ipBefore & 0xFFFFFFFF,
                $maskedAfter,
                $bytes,
                $cf,
                $zf,
                $sf,
                $of,
            ));
        }

        throw new HaltException('Stopped by PHPME_STOP_ON_CFLOW_TO');
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
     * Get total executed instruction count (when `PHPME_COUNT_INSNS` or IP sampling is enabled).
     */
    public function instructionCount(): int
    {
        return $this->executedInstructions;
    }

    /**
     * Get IP sampling report.
     *
     * @return array{every:int,instructions:int,samples:int,unique:int,top:array<int,array{int,int}>}
     */
    public function getIpSampleReport(int $top = 20): array
    {
        $every = $this->ipSampleEvery();
        if ($every <= 0 || $this->ipSampleHits === []) {
            return [
                'every' => $every,
                'instructions' => $this->executedInstructions,
                'samples' => 0,
                'unique' => 0,
                'top' => [],
            ];
        }

        $total = 0;
        foreach ($this->ipSampleHits as $c) {
            $total += $c;
        }

        $hits = $this->ipSampleHits;
        arsort($hits);

        $topList = [];
        foreach ($hits as $ip => $count) {
            $topList[] = [(int) $ip, (int) $count];
            if (count($topList) >= $top) {
                break;
            }
        }

        return [
            'every' => $every,
            'instructions' => $this->executedInstructions,
            'samples' => $total,
            'unique' => count($this->ipSampleHits),
            'top' => $topList,
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
        $this->traceExecution = null;
        $this->patternedInstructionsList->invalidateCaches();
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
