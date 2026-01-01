<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\PatternedInstruction;

class PatternedInstructionsListStats
{
    public function __construct(
        private int $compiledPatterns = 0,
        private int $noPatternIps = 0,
        private int $hotIps = 0,
        private int $cacheHits = 0,
        private int $cacheMisses = 0,
    ) {
    }

    public function compiledPatterns(): int
    {
        return $this->compiledPatterns;
    }

    public function noPatternIps(): int
    {
        return $this->noPatternIps;
    }

    public function hotIps(): int
    {
        return $this->hotIps;
    }

    public function cacheHits(): int
    {
        return $this->cacheHits;
    }

    public function cacheMisses(): int
    {
        return $this->cacheMisses;
    }

    public function incrementCompiledPatterns(): void
    {
        $this->compiledPatterns++;
    }

    public function incrementNoPatternIps(): void
    {
        $this->noPatternIps++;
    }

    public function incrementHotIps(): void
    {
        $this->hotIps++;
    }

    public function incrementCacheHits(): void
    {
        $this->cacheHits++;
    }

    public function incrementCacheMisses(): void
    {
        $this->cacheMisses++;
    }

    public function setCompiledPatterns(int $count): void
    {
        $this->compiledPatterns = $count;
    }

    public function setNoPatternIps(int $count): void
    {
        $this->noPatternIps = $count;
    }

    public function setHotIps(int $count): void
    {
        $this->hotIps = $count;
    }

    public function reset(): void
    {
        $this->compiledPatterns = 0;
        $this->noPatternIps = 0;
        $this->hotIps = 0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
    }

    public function toArray(): array
    {
        return [
            'compiled_patterns' => $this->compiledPatterns,
            'no_pattern_ips' => $this->noPatternIps,
            'hot_ips' => $this->hotIps,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
        ];
    }
}
