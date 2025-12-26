<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class DebugContext implements DebugContextInterface
{
    /**
     * @param array<int,true> $traceIpSet
     * @param array<int,true> $stopIpSet
     * @param array<int,true> $traceCflowToSet
     * @param array<int,true> $stopCflowToSet
     */
    public function __construct(
        private bool $countInstructionsEnabled = false,
        private int $ipSampleEvery = 0,
        private int $stopAfterInsns = 0,
        private int $stopAfterSecs = 0,
        private int $stopAfterTimeEvery = 10000,
        private ?bool $traceExecution = null,
        private array $traceIpSet = [],
        private int $traceIpLimit = 10,
        private array $stopIpSet = [],
        private array $traceCflowToSet = [],
        private int $traceCflowLimit = 10,
        private array $stopCflowToSet = [],
        private int $stopOnRspBelowThreshold = 0,
        private int $stopOnCflowToBelowThreshold = 0,
        private int $stopOnIpDropBelowThreshold = 0,
        private int $zeroOpcodeLoopLimit = 0,
        private int $stackPreviewOnIpStopBytes = 0,
        private int $dumpCodeOnIpStopLength = 0,
        private int $dumpCodeOnIpStopBefore = 0,
        private bool $dumpPageFaultContext = false,
        private int $dumpCodeOnPfLength = 0,
        private int $dumpCodeOnPfBefore = 0,
        private int $pfComparePhysDelta = 0,
        private bool $stopOnIa32eActive = false,
        private ?ScreenDebugConfig $screenConfig = null,
        private ?MemoryAccessDebugConfig $memoryAccessConfig = null,
        private ?PatternDebugConfig $patternConfig = null,
        private ?TraceDebugConfig $traceConfig = null,
        private ?WatchDebugConfig $watchConfig = null,
        private ?WatchState $watchState = null,
        private ?BootConfigPatchConfig $bootConfig = null,
    ) {
        $this->screenConfig ??= new ScreenDebugConfig();
        $this->memoryAccessConfig ??= new MemoryAccessDebugConfig();
        $this->patternConfig ??= new PatternDebugConfig();
        $this->traceConfig ??= new TraceDebugConfig();
        $this->watchConfig ??= new WatchDebugConfig();
        $this->watchState ??= new WatchState();
        if ($this->watchConfig->access?->armAfterInt13Lba !== null) {
            $this->watchState->setWatchArmAfterInt13Lba($this->watchConfig->access->armAfterInt13Lba);
        }
        $this->bootConfig ??= new BootConfigPatchConfig();
    }

    public function countInstructionsEnabled(): bool
    {
        return $this->countInstructionsEnabled;
    }

    public function ipSampleEvery(): int
    {
        return $this->ipSampleEvery;
    }

    public function stopAfterInsns(): int
    {
        return $this->stopAfterInsns;
    }

    public function stopAfterSecs(): int
    {
        return $this->stopAfterSecs;
    }

    public function stopAfterTimeEvery(): int
    {
        return $this->stopAfterTimeEvery;
    }

    public function traceExecution(): ?bool
    {
        return $this->traceExecution;
    }

    public function traceIpSet(): array
    {
        return $this->traceIpSet;
    }

    public function stopIpSet(): array
    {
        return $this->stopIpSet;
    }

    public function traceIpLimit(): int
    {
        return $this->traceIpLimit;
    }

    public function traceCflowToSet(): array
    {
        return $this->traceCflowToSet;
    }

    public function stopCflowToSet(): array
    {
        return $this->stopCflowToSet;
    }

    public function traceCflowLimit(): int
    {
        return $this->traceCflowLimit;
    }

    public function stopOnRspBelowThreshold(): int
    {
        return $this->stopOnRspBelowThreshold;
    }

    public function stopOnCflowToBelowThreshold(): int
    {
        return $this->stopOnCflowToBelowThreshold;
    }

    public function stopOnIpDropBelowThreshold(): int
    {
        return $this->stopOnIpDropBelowThreshold;
    }

    public function zeroOpcodeLoopLimit(): int
    {
        return $this->zeroOpcodeLoopLimit;
    }

    public function stackPreviewOnIpStopBytes(): int
    {
        return $this->stackPreviewOnIpStopBytes;
    }

    public function dumpCodeOnIpStopLength(): int
    {
        return $this->dumpCodeOnIpStopLength;
    }

    public function dumpCodeOnIpStopBefore(): int
    {
        return $this->dumpCodeOnIpStopBefore;
    }

    public function dumpPageFaultContext(): bool
    {
        return $this->dumpPageFaultContext;
    }

    public function dumpCodeOnPfLength(): int
    {
        return $this->dumpCodeOnPfLength;
    }

    public function dumpCodeOnPfBefore(): int
    {
        return $this->dumpCodeOnPfBefore;
    }

    public function pfComparePhysDelta(): int
    {
        return $this->pfComparePhysDelta;
    }

    public function stopOnIa32eActive(): bool
    {
        return $this->stopOnIa32eActive;
    }

    public function screen(): ScreenDebugConfig
    {
        return $this->screenConfig ??= new ScreenDebugConfig();
    }

    public function memoryAccess(): MemoryAccessDebugConfig
    {
        return $this->memoryAccessConfig ??= new MemoryAccessDebugConfig();
    }

    public function patterns(): PatternDebugConfig
    {
        return $this->patternConfig ??= new PatternDebugConfig();
    }

    public function trace(): TraceDebugConfig
    {
        return $this->traceConfig ??= new TraceDebugConfig();
    }

    public function watch(): WatchDebugConfig
    {
        return $this->watchConfig ??= new WatchDebugConfig();
    }

    public function watchState(): WatchState
    {
        return $this->watchState ??= new WatchState();
    }

    public function bootConfig(): BootConfigPatchConfig
    {
        return $this->bootConfig ??= new BootConfigPatchConfig();
    }
}
