<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

use PHPMachineEmulator\LogicBoard\Debug\PatternDebugConfig;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * PatternedInstructionsList - Manages collection of patterned instructions
 * and handles pattern detection, compilation, and execution.
 *
 * This is similar to a simple JIT compiler that recognizes common patterns
 * and executes them directly in PHP for better performance.
 */
class PatternedInstructionsList
{
    /**
     * Registered patterns, sorted by priority (highest first)
     * @var array<PatternedInstructionInterface>
     */
    private array $patterns = [];

    /**
     * Hit count per IP for pattern detection
     * @var array<int, int>
     */
    private array $hitCount = [];

    /**
     * Detected patterns: IP => callable
     * @var array<int, callable>
     */
    private array $compiledPatterns = [];

    /**
     * IPs that were checked but no pattern found
     * @var array<int, bool>
     */
    private array $noPatternFound = [];

    /**
     * Statistics tracking
     */
    private PatternedInstructionsListStats $stats;
    private PatternDebugConfig $debugConfig;

    /**
     * Threshold for pattern detection (hits before we check for patterns)
     */
    private const DETECTION_THRESHOLD = 10;
    private const PATTERN_READ_BYTES = 96;

    public function __construct(?PatternDebugConfig $debugConfig = null)
    {
        $this->stats = new PatternedInstructionsListStats();
        $this->debugConfig = $debugConfig ?? new PatternDebugConfig();
        $this->registerDefaultPatterns();
    }

    /**
     * Register default patterns in priority order.
     */
    private function registerDefaultPatterns(): void
    {
        // The GRUB/LZMA range-decoder pattern is high-impact but correctness-sensitive.
        // Keep it behind a config flag to allow quick disable if regressions appear.
        if ($this->debugConfig->enableLzmaPattern) {
            $this->register(new LzmaRangeDecodeBitPattern($this->debugConfig->traceHotPatterns));
        }
        $this->register(new UdivmoddiPattern($this->debugConfig->traceHotPatterns));
        $this->register(new MemsetDwordLoopPattern($this->debugConfig->traceHotPatterns));
        $this->register(new MemmoveBackwardLoopPattern($this->debugConfig->traceHotPatterns));
        $this->register(new MovsbLoopPattern($this->debugConfig->traceHotPatterns));
        $this->register(new StrcpyLoopPattern($this->debugConfig->traceHotPatterns));
        $this->register(new ShrdShlPattern());
        $this->register(new AddAdcPattern());
        $this->register(new CmpJccPattern());
        $this->register(new ShiftLeftLoopPattern());
        $this->register(new ShiftRightLoopPattern());
        $this->register(new CarryCheckLoopPattern());
        $this->register(new IncCmpLoopPattern());
    }

    /**
     * Register a pattern.
     */
    public function register(PatternedInstructionInterface $pattern): void
    {
        $this->patterns[] = $pattern;

        // Sort patterns by priority (highest first)
        usort($this->patterns, fn($a, $b) => $b->priority() <=> $a->priority());
    }

    /**
     * Try to detect and execute a hot pattern at the current IP.
     *
     * @return PatternedInstructionResult|null Result or null if no pattern
     */
    public function tryExecutePattern(RuntimeInterface $runtime, int $ip): ?PatternedInstructionResult
    {
        // Fast path: check if we already have a compiled pattern
        if (isset($this->compiledPatterns[$ip])) {
            $this->stats->incrementCacheHits();
            return ($this->compiledPatterns[$ip])($runtime);
        }

        // Fast path: already checked and no pattern found
        if (isset($this->noPatternFound[$ip])) {
            return null;
        }

        // Count hits
        $hits = ($this->hitCount[$ip] ?? 0) + 1;
        $this->hitCount[$ip] = $hits;

        // Only try to detect patterns after threshold hits
        if ($hits < self::DETECTION_THRESHOLD) {
            return null;
        }

        // Try to compile a pattern
        if ($hits === self::DETECTION_THRESHOLD) {
            $this->stats->incrementCacheMisses();
            $compiled = $this->tryCompilePattern($runtime, $ip);
            if ($compiled !== null) {
                $this->compiledPatterns[$ip] = $compiled;
                $this->stats->incrementCompiledPatterns();
                $runtime->option()->logger()->debug(sprintf(
                    'HOT PATTERN compiled at IP=0x%X',
                    $ip
                ));
                return $compiled($runtime);
            } else {
                $this->noPatternFound[$ip] = true;
                $this->stats->incrementNoPatternIps();
            }
        }

        return null;
    }

    /**
     * Try to compile a pattern at the given IP.
     *
     * @return callable|null Returns a callable that executes the pattern, or null if no pattern found
     */
    private function tryCompilePattern(RuntimeInterface $runtime, int $ip): ?callable
    {
        $memory = $runtime->memory();
        $savedOffset = $memory->offset();

        // Read instruction bytes
        $memory->setOffset($ip);
        $bytes = [];
        for ($i = 0; $i < self::PATTERN_READ_BYTES && !$memory->isEOF(); $i++) {
            $bytes[] = $memory->byte();
        }
        $memory->setOffset($savedOffset);

        // Try each pattern detector (sorted by priority)
        foreach ($this->patterns as $pattern) {
            $compiled = $pattern->tryCompile($ip, $bytes);
            if ($compiled !== null) {
                return $compiled;
            }
        }

        return null;
    }

    /**
     * Invalidate all pattern caches.
     */
    public function invalidateCaches(): void
    {
        $this->hitCount = [];
        $this->compiledPatterns = [];
        $this->noPatternFound = [];
        $this->stats->reset();
    }

    /**
     * Get statistics about pattern detection.
     */
    public function getStats(): PatternedInstructionsListStats
    {
        $hotIps = 0;
        foreach ($this->hitCount as $count) {
            if ($count >= self::DETECTION_THRESHOLD) {
                $hotIps++;
            }
        }
        $this->stats->setHotIps($hotIps);
        $this->stats->setCompiledPatterns(count($this->compiledPatterns));
        $this->stats->setNoPatternIps(count($this->noPatternFound));

        return $this->stats;
    }

    /**
     * Get registered patterns.
     *
     * @return array<PatternedInstructionInterface>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
