<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

interface DebugContextInterface
{
    public function countInstructionsEnabled(): bool;

    public function ipSampleEvery(): int;

    public function stopAfterInsns(): int;

    public function stopAfterSecs(): int;

    public function stopAfterTimeEvery(): int;

    public function traceExecution(): ?bool;

    /**
     * @return array<int,true>
     */
    public function traceIpSet(): array;

    /**
     * @return array<int,true>
     */
    public function stopIpSet(): array;

    public function traceIpLimit(): int;

    /**
     * @return array<int,true>
     */
    public function traceCflowToSet(): array;

    /**
     * @return array<int,true>
     */
    public function stopCflowToSet(): array;

    public function traceCflowLimit(): int;

    public function stopOnRspBelowThreshold(): int;

    public function stopOnCflowToBelowThreshold(): int;

    public function zeroOpcodeLoopLimit(): int;

    public function stackPreviewOnIpStopBytes(): int;

    public function dumpCodeOnIpStopLength(): int;

    public function dumpCodeOnIpStopBefore(): int;

    public function dumpPageFaultContext(): bool;

    public function dumpCodeOnPfLength(): int;

    public function dumpCodeOnPfBefore(): int;

    public function pfComparePhysDelta(): int;

    public function stopOnIa32eActive(): bool;

    public function screen(): ScreenDebugConfig;

    public function memoryAccess(): MemoryAccessDebugConfig;

    public function patterns(): PatternDebugConfig;

    public function trace(): TraceDebugConfig;

    public function watch(): WatchDebugConfig;

    public function watchState(): WatchState;

    public function bootConfig(): BootConfigPatchConfig;
}
