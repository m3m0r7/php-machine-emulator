<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Debug\DebugContextInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

final class InstructionExecutorDebug
{
    private bool $countInstructionsEnabled;
    private int $ipSampleEvery;
    private int $stopAfterInsns;
    private int $stopAfterInsnsRemaining;
    private int $stopAfterSecs;
    private ?float $stopAfterDeadline = null;
    private int $stopAfterTimeEvery;
    private int $stopAfterTimeCountdown = 0;

    private ?bool $traceExecutionOverride;
    private ?bool $traceExecutionCache = null;

    /**
     * @var array<int,true>
     */
    private array $traceIpSet;

    /**
     * @var array<int,true>
     */
    private array $stopIpSet;

    private int $traceIpLimit;

    /**
     * @var array<int,int>
     */
    private array $traceIpCounts = [];

    /**
     * @var array<int,true>
     */
    private array $traceCflowToSet;

    /**
     * @var array<int,true>
     */
    private array $stopCflowToSet;

    private int $traceCflowLimit;

    /**
     * @var array<int,int>
     */
    private array $traceCflowCounts = [];

    private int $stopOnRspBelowThreshold;
    private int $stopOnCflowToBelowThreshold;
    private int $zeroOpcodeLoopLimit;
    private int $stackPreviewOnIpStopBytes;
    private int $dumpCodeOnIpStopLength;
    private int $dumpCodeOnIpStopBefore;
    private bool $dumpPageFaultContext;
    private int $dumpCodeOnPfLength;
    private int $dumpCodeOnPfBefore;
    private int $pfComparePhysDelta;

    /**
     * Optional executed instruction counter (for profiling/debugging).
     */
    private int $executedInstructions = 0;

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

    public function __construct(DebugContextInterface $debugContext)
    {
        $this->countInstructionsEnabled = $debugContext->countInstructionsEnabled();
        $this->ipSampleEvery = $debugContext->ipSampleEvery();
        $this->stopAfterInsns = $debugContext->stopAfterInsns();
        $this->stopAfterInsnsRemaining = $this->stopAfterInsns;
        $this->stopAfterSecs = $debugContext->stopAfterSecs();
        $this->stopAfterTimeEvery = $debugContext->stopAfterTimeEvery();
        $this->traceExecutionOverride = $debugContext->traceExecution();
        $this->traceIpSet = $debugContext->traceIpSet();
        $this->stopIpSet = $debugContext->stopIpSet();
        $this->traceIpLimit = $debugContext->traceIpLimit();
        $this->traceCflowToSet = $debugContext->traceCflowToSet();
        $this->stopCflowToSet = $debugContext->stopCflowToSet();
        $this->traceCflowLimit = $debugContext->traceCflowLimit();
        $this->stopOnRspBelowThreshold = $debugContext->stopOnRspBelowThreshold();
        $this->stopOnCflowToBelowThreshold = $debugContext->stopOnCflowToBelowThreshold();
        $this->zeroOpcodeLoopLimit = $debugContext->zeroOpcodeLoopLimit();
        $this->stackPreviewOnIpStopBytes = $debugContext->stackPreviewOnIpStopBytes();
        $this->dumpCodeOnIpStopLength = $debugContext->dumpCodeOnIpStopLength();
        $this->dumpCodeOnIpStopBefore = $debugContext->dumpCodeOnIpStopBefore();
        $this->dumpPageFaultContext = $debugContext->dumpPageFaultContext();
        $this->dumpCodeOnPfLength = $debugContext->dumpCodeOnPfLength();
        $this->dumpCodeOnPfBefore = $debugContext->dumpCodeOnPfBefore();
        $this->pfComparePhysDelta = $debugContext->pfComparePhysDelta();
    }

    public function resetTraceCache(): void
    {
        $this->traceExecutionCache = null;
    }

    public function instructionCount(): int
    {
        return $this->executedInstructions;
    }

    public function zeroOpcodeLoopLimit(): int
    {
        return $this->zeroOpcodeLoopLimit;
    }

    /**
     * Get IP sampling report.
     *
     * @return array{every:int,instructions:int,samples:int,unique:int,top:array<int,array{int,int}>}
     */
    public function getIpSampleReport(int $top = 20): array
    {
        $every = $this->ipSampleEvery;
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

    public function recordExecution(RuntimeInterface $runtime, int $ip): void
    {
        if ($this->ipSampleEvery <= 0
            && !$this->countInstructionsEnabled
            && $this->stopAfterInsns <= 0
            && $this->stopAfterSecs <= 0
        ) {
            return;
        }

        $this->executedInstructions++;
        $this->maybeStopAfter($runtime);

        if ($this->ipSampleEvery <= 0) {
            return;
        }

        if ($this->ipSampleCountdown <= 0) {
            $this->ipSampleCountdown = $this->ipSampleEvery;
        }

        $this->ipSampleCountdown--;
        if ($this->ipSampleCountdown !== 0) {
            return;
        }

        $this->ipSampleHits[$ip] = ($this->ipSampleHits[$ip] ?? 0) + 1;
        $this->ipSampleCountdown = $this->ipSampleEvery;
    }

    public function shouldTraceExecution(RuntimeInterface $runtime): bool
    {
        if ($this->traceExecutionCache !== null) {
            return $this->traceExecutionCache;
        }

        if ($this->traceExecutionOverride !== null) {
            $this->traceExecutionCache = $this->traceExecutionOverride;
            return $this->traceExecutionCache;
        }

        $logger = $runtime->option()->logger();
        if ($logger instanceof \Monolog\Logger) {
            $this->traceExecutionCache = $logger->isHandling(\Monolog\Level::Debug);
            return $this->traceExecutionCache;
        }

        $this->traceExecutionCache = false;
        return false;
    }

    /**
     * Trace execution at specific IPs for debugging.
     */
    public function maybeTraceIp(RuntimeInterface $runtime, int $ip): void
    {
        if ($this->traceIpSet === [] || !isset($this->traceIpSet[$ip & 0xFFFFFFFF])) {
            return;
        }

        $limit = $this->traceIpLimit;
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

    public function maybeTraceControlFlowTarget(
        RuntimeInterface $runtime,
        int $ipBefore,
        int $ipAfter,
        string $source,
        ?string $bytes = null,
    ): void {
        $maskedAfter = $ipAfter & 0xFFFFFFFF;
        if ($this->traceCflowToSet === [] || !isset($this->traceCflowToSet[$maskedAfter])) {
            return;
        }

        $limit = $this->traceCflowLimit;
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

    public function maybeStopAtIp(
        RuntimeInterface $runtime,
        int $ip,
        int $prevIp,
        ?InstructionInterface $prevInstruction,
        ?array $prevOpcodes,
    ): void {
        $masked = $ip & 0xFFFFFFFF;
        if ($this->stopIpSet === [] || !isset($this->stopIpSet[$masked])) {
            return;
        }

        $cpu = $runtime->context()->cpu();
        $ma = $runtime->memoryAccessor();
        $prevOpcodeStr = $prevOpcodes === null
            ? 'n/a'
            : implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $prevOpcodes));
        $prevInstructionName = $prevInstruction === null
            ? 'n/a'
            : (preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($prevInstruction)) ?? 'n/a');

        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($ip);

        $bytes = [];
        for ($i = 0; $i < 16 && !$memory->isEOF(); $i++) {
            $bytes[] = $memory->byte();
        }

        $hex = implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $bytes));

        $runtime->option()->logger()->warning(sprintf(
            'STOP_AT_IP: ip=0x%08X bytes=%s PM=%d PG=%d LM=%d op=%d addr=%d A20=%d CS=0x%04X prevIP=0x%08X prevIns=%s prevOp=%s',
            $masked,
            $hex,
            $cpu->isProtectedMode() ? 1 : 0,
            $cpu->isPagingEnabled() ? 1 : 0,
            $cpu->isLongMode() ? 1 : 0,
            $cpu->operandSize(),
            $cpu->addressSize(),
            $cpu->isA20Enabled() ? 1 : 0,
            $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF,
            $prevIp & 0xFFFFFFFF,
            $prevInstructionName,
            $prevOpcodeStr,
        ));

        if ($this->stackPreviewOnIpStopBytes > 0) {
            $len = $this->stackPreviewOnIpStopBytes;
            $rsp = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
            $linearMask = $cpu->isLongMode() ? 0x0000FFFFFFFFFFFF : ($cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF);
            $linear = $rsp & $linearMask;
            [$phys, $err] = $ma->translateLinear($linear, false, $cpu->cpl() === 3, $cpu->isPagingEnabled(), $linearMask);
            if (((int) $err) === 0) {
                $hexStack = '';
                for ($i = 0; $i < $len; $i++) {
                    $b = $ma->readPhysical8((((int) $phys) + $i) & 0xFFFFFFFF) & 0xFF;
                    $hexStack .= sprintf('%02X', $b);
                }
                $runtime->option()->logger()->warning(sprintf(
                    'STOP_AT_IP: rsp=0x%016X ss=0x%04X stackPhys=0x%08X bytes=%s',
                    $rsp,
                    $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF,
                    ((int) $phys) & 0xFFFFFFFF,
                    $hexStack,
                ));
            } else {
                $runtime->option()->logger()->warning(sprintf(
                    'STOP_AT_IP: rsp=0x%016X ss=0x%04X stackTranslate failed linear=0x%016X err=0x%08X',
                    $rsp,
                    $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF,
                    $linear,
                    ((int) $err) & 0xFFFFFFFF,
                ));
            }
        }

        if ($this->dumpCodeOnIpStopLength > 0) {
            $len = $this->dumpCodeOnIpStopLength;
            $before = $this->dumpCodeOnIpStopBefore;
            $start = max(0, ($ip - max(0, $before)));
            $memory->setOffset($start);
            $data = '';
            for ($i = 0; $i < $len && !$memory->isEOF(); $i++) {
                $data .= chr($memory->byte() & 0xFF);
            }
            @file_put_contents(sprintf('debug/codedump_ip_%08X_%d.bin', $masked, $len), $data);
            $runtime->option()->logger()->warning(sprintf(
                'STOP_AT_IP: dumped %d bytes at 0x%08X to debug/codedump_ip_%08X_%d.bin',
                $len,
                $start & 0xFFFFFFFF,
                $masked,
                $len,
            ));
        }

        $memory->setOffset($saved);

        throw new HaltException('Stopped by PHPME_STOP_AT_IP');
    }

    public function maybeStopOnRspBelow(RuntimeInterface $runtime, int $ip, int $prevIp): void
    {
        if ($this->stopOnRspBelowThreshold <= 0) {
            return;
        }

        $cpu = $runtime->context()->cpu();
        if (!$cpu->isLongMode() || $cpu->isCompatibilityMode()) {
            return;
        }

        $ma = $runtime->memoryAccessor();
        $rsp = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
        if ($rsp < 0 || $rsp >= $this->stopOnRspBelowThreshold) {
            return;
        }

        $runtime->option()->logger()->warning(sprintf(
            'STOP: RSP below threshold at ip=0x%08X rsp=0x%016X thresh=0x%08X CS=0x%04X SS=0x%04X prevIP=0x%08X',
            $ip & 0xFFFFFFFF,
            $rsp,
            $this->stopOnRspBelowThreshold & 0xFFFFFFFF,
            $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF,
            $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF,
            $prevIp & 0xFFFFFFFF,
        ));
        throw new HaltException('Stopped by PHPME_STOP_ON_RSP_BELOW_EXEC');
    }

    public function maybeStopOnControlFlowTarget(
        RuntimeInterface $runtime,
        int $ipBefore,
        int $ipAfter,
        ?InstructionInterface $instruction,
        ?array $opcodes,
        string $source,
    ): void {
        if ($this->stopOnCflowToBelowThreshold > 0 && $runtime->context()->cpu()->isLongMode()) {
            $maskedAfter = $ipAfter & 0xFFFFFFFF;
            if ($maskedAfter < ($this->stopOnCflowToBelowThreshold & 0xFFFFFFFF)) {
                $mnemonic = $source;
                if ($instruction !== null) {
                    $mnemonic = preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($instruction)) ?? $source;
                }
                $bytes = $opcodes === null
                    ? null
                    : implode(' ', array_map(static fn (int $b): string => sprintf('%02X', $b & 0xFF), $opcodes));
                $runtime->option()->logger()->warning(sprintf(
                    'STOP_CFLOW_BELOW: %s ip=0x%08X -> 0x%08X thresh=0x%08X bytes=%s',
                    $mnemonic,
                    $ipBefore & 0xFFFFFFFF,
                    $maskedAfter,
                    $this->stopOnCflowToBelowThreshold & 0xFFFFFFFF,
                    $bytes ?? 'n/a',
                ));
                throw new HaltException('Stopped by PHPME_STOP_ON_CFLOW_TO_BELOW');
            }
        }

        $maskedAfter = $ipAfter & 0xFFFFFFFF;
        if ($this->stopCflowToSet === [] || !isset($this->stopCflowToSet[$maskedAfter])) {
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

    public function maybeDumpPageFaultContext(RuntimeInterface $runtime, FaultException $e, int $ip): void
    {
        if (($e->vector() & 0xFF) !== 0x0E) {
            return;
        }

        if (!$this->dumpPageFaultContext) {
            return;
        }

        $cpu = $runtime->context()->cpu();
        $ma = $runtime->memoryAccessor();

        $cs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $ss = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;
        $rsp = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
        $rax = $ma->fetch(RegisterType::EAX)->asBytesBySize(64);
        $rbx = $ma->fetch(RegisterType::EBX)->asBytesBySize(64);
        $rcx = $ma->fetch(RegisterType::ECX)->asBytesBySize(64);
        $rdx = $ma->fetch(RegisterType::EDX)->asBytesBySize(64);
        $rsi = $ma->fetch(RegisterType::ESI)->asBytesBySize(64);
        $rdi = $ma->fetch(RegisterType::EDI)->asBytesBySize(64);
        $cr0 = $ma->readControlRegister(0);
        $cr2 = $ma->readControlRegister(2);
        $cr3 = $ma->readControlRegister(3);
        $cr4 = $ma->readControlRegister(4);
        $efer = $ma->readEfer();

        $idtr = $cpu->idtr();
        $gdtr = $cpu->gdtr();
        $tr = $cpu->taskRegister();

        $runtime->option()->logger()->warning(sprintf(
            'PFCTX: rip=0x%016X cs=0x%04X ss=0x%04X rsp=0x%016X rax=0x%016X rbx=0x%016X rcx=0x%016X rdx=0x%016X rsi=0x%016X rdi=0x%016X cr0=0x%016X cr2=0x%016X cr3=0x%016X cr4=0x%016X efer=0x%016X cpl=%d PM=%d PG=%d LM=%d CM=%d op=%d addr=%d A20=%d idtr.base=0x%016X idtr.limit=0x%04X gdtr.base=0x%016X gdtr.limit=0x%04X tr.sel=0x%04X tr.base=0x%016X tr.limit=0x%08X',
            $ip,
            $cs,
            $ss,
            $rsp,
            $rax,
            $rbx,
            $rcx,
            $rdx,
            $rsi,
            $rdi,
            $cr0,
            $cr2,
            $cr3,
            $cr4,
            $efer,
            $cpu->cpl(),
            $cpu->isProtectedMode() ? 1 : 0,
            $cpu->isPagingEnabled() ? 1 : 0,
            $cpu->isLongMode() ? 1 : 0,
            $cpu->isCompatibilityMode() ? 1 : 0,
            $cpu->operandSize(),
            $cpu->addressSize(),
            $cpu->isA20Enabled() ? 1 : 0,
            (int) ($idtr['base'] ?? 0),
            (int) ($idtr['limit'] ?? 0),
            (int) ($gdtr['base'] ?? 0),
            (int) ($gdtr['limit'] ?? 0),
            (int) ($tr['selector'] ?? 0),
            (int) ($tr['base'] ?? 0),
            (int) ($tr['limit'] ?? 0),
        ));

        if ($this->dumpCodeOnPfLength > 0) {
            $len = $this->dumpCodeOnPfLength;
            $before = $this->dumpCodeOnPfBefore;
            $start = max(0, ($ip - max(0, $before)));

            $saved = $runtime->memory()->offset();
            $runtime->memory()->setOffset($start);
            $data = '';
            for ($i = 0; $i < $len && !$runtime->memory()->isEOF(); $i++) {
                $data .= chr($runtime->memory()->byte() & 0xFF);
            }
            $runtime->memory()->setOffset($saved);

            @file_put_contents(sprintf('debug/codedump_pf_%08X_%d.bin', $ip & 0xFFFFFFFF, $len), $data);
            $runtime->option()->logger()->warning(sprintf(
                'PFCTX: dumped %d bytes at 0x%08X to debug/codedump_pf_%08X_%d.bin',
                $len,
                $start & 0xFFFFFFFF,
                $ip & 0xFFFFFFFF,
                $len,
            ));
        }

        if ($this->pfComparePhysDelta !== 0) {
            $readPhysBytes = static function (RuntimeInterface $runtime, int $addr, int $len): string {
                $ma = $runtime->memoryAccessor();
                $hex = '';
                for ($i = 0; $i < $len; $i++) {
                    $b = $ma->readPhysical8(((int) $addr + $i) & 0xFFFFFFFF) & 0xFF;
                    $hex .= sprintf('%02X', $b);
                }
                return $hex;
            };

            $a = $ip & 0xFFFFFFFF;
            $b = ($a + $this->pfComparePhysDelta) & 0xFFFFFFFF;
            $hexA = $readPhysBytes($runtime, $a, 16);
            $hexB = $readPhysBytes($runtime, $b, 16);
            $runtime->option()->logger()->warning(sprintf(
                'PFCTX: phys bytes @0x%08X=%s @0x%08X=%s (delta=0x%X)',
                $a,
                $hexA,
                $b,
                $hexB,
                $this->pfComparePhysDelta & 0xFFFFFFFF,
            ));
        }

        $linearMask = $cpu->isLongMode() ? 0x0000FFFFFFFFFFFF : ($cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF);
        $isUser = $cpu->cpl() === 3;
        $pagingEnabled = $cpu->isPagingEnabled();
        $idtrBase = (int) ($idtr['base'] ?? 0);
        $idtrLimit = (int) ($idtr['limit'] ?? 0);

        $readPhys64 = static function (RuntimeInterface $runtime, int $phys): int {
            $ma = $runtime->memoryAccessor();
            $lo = $ma->readPhysical32($phys & 0xFFFFFFFF);
            $hi = $ma->readPhysical32(($phys + 4) & 0xFFFFFFFF);
            return (($hi & 0xFFFFFFFF) << 32) | ($lo & 0xFFFFFFFF);
        };

        $dumpIdtGate = function (int $vector) use ($runtime, $ma, $cpu, $linearMask, $isUser, $pagingEnabled, $idtrBase, $idtrLimit): void {
            $entrySize = ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) ? 16 : 8;
            $entryOffset = $vector * $entrySize;
            if ($entryOffset + ($entrySize - 1) > $idtrLimit) {
                $runtime->option()->logger()->warning(sprintf('IDT[%02X]: out of bounds (limit=0x%X)', $vector & 0xFF, $idtrLimit & 0xFFFF));
                return;
            }

            $entryLinear = $idtrBase + $entryOffset;
            [$entryPhys, $err] = $ma->translateLinear($entryLinear, false, $isUser, $pagingEnabled, $linearMask);
            if ($err !== 0) {
                $runtime->option()->logger()->warning(sprintf(
                    'IDT[%02X]: translate failed linear=0x%016X err=0x%08X',
                    $vector & 0xFF,
                    $entryLinear,
                    $err & 0xFFFFFFFF,
                ));
                return;
            }

            $raw = [];
            $hex = '';
            for ($i = 0; $i < $entrySize; $i++) {
                $b = $ma->readPhysical8(((int) $entryPhys + $i) & 0xFFFFFFFF) & 0xFF;
                $raw[] = $b;
                $hex .= sprintf('%02X', $b);
            }

            $runtime->option()->logger()->warning(sprintf(
                'IDT[%02X]: phys=0x%08X bytes=%s',
                $vector & 0xFF,
                ((int) $entryPhys) & 0xFFFFFFFF,
                $hex,
            ));

            if ($entrySize !== 16 || count($raw) !== 16) {
                return;
            }

            $offsetLow = ($raw[0] ?? 0) | (($raw[1] ?? 0) << 8);
            $selector = ($raw[2] ?? 0) | (($raw[3] ?? 0) << 8);
            $ist = ($raw[4] ?? 0) & 0x7;
            $typeAttr = $raw[5] ?? 0;
            $offsetMid = ($raw[6] ?? 0) | (($raw[7] ?? 0) << 8);
            $offsetHigh = ($raw[8] ?? 0) | (($raw[9] ?? 0) << 8) | (($raw[10] ?? 0) << 16) | (($raw[11] ?? 0) << 24);
            $offsetLow32 = (($offsetLow & 0xFFFF) | (($offsetMid & 0xFFFF) << 16)) & 0xFFFFFFFF;
            $handler = (($offsetHigh & 0xFFFFFFFF) << 32) | $offsetLow32;

            $runtime->option()->logger()->warning(sprintf(
                'IDT[%02X]: sel=0x%04X ist=%d typeAttr=0x%02X handler=0x%016X',
                $vector & 0xFF,
                $selector & 0xFFFF,
                $ist,
                $typeAttr & 0xFF,
                $handler,
            ));
        };

        $dumpIdtGate(0x0E);
        $dumpIdtGate(0x08);

        $linear = $cr2 & 0x0000FFFFFFFFFFFF;
        $pml4Index = ($linear >> 39) & 0x1FF;
        $pdptIndex = ($linear >> 30) & 0x1FF;
        $pdIndex = ($linear >> 21) & 0x1FF;
        $ptIndex = ($linear >> 12) & 0x1FF;
        $pageOffset = $linear & 0xFFF;

        $pml4Base = $cr3 & 0xFFFFF000;
        $pml4eAddr = ($pml4Base + ($pml4Index * 8)) & 0xFFFFFFFF;
        $pml4e = $readPhys64($runtime, $pml4eAddr);

        $runtime->option()->logger()->warning(sprintf(
            'PFTBL: cr3=0x%016X cr2=0x%016X idx[pml4=%d pdpt=%d pd=%d pt=%d off=0x%03X]',
            $cr3,
            $cr2,
            $pml4Index,
            $pdptIndex,
            $pdIndex,
            $ptIndex,
            $pageOffset,
        ));

        $dumpEntry = static function (string $name, int $addr, int $val) use ($runtime): void {
            $runtime->option()->logger()->warning(sprintf(
                'PFTBL: %s@0x%08X=0x%016X P=%d W=%d U=%d A=%d D=%d PS=%d',
                $name,
                $addr & 0xFFFFFFFF,
                $val,
                ($val & 0x1) !== 0 ? 1 : 0,
                ($val & 0x2) !== 0 ? 1 : 0,
                ($val & 0x4) !== 0 ? 1 : 0,
                ($val & 0x20) !== 0 ? 1 : 0,
                ($val & 0x40) !== 0 ? 1 : 0,
                ($val & 0x80) !== 0 ? 1 : 0,
            ));
        };

        $dumpEntry('PML4E', $pml4eAddr, $pml4e);
        if (($pml4e & 0x1) === 0) {
            return;
        }

        $pdptBase = $pml4e & 0x000FFFFFFFFFF000;
        $pdpteAddr = (($pdptBase + ($pdptIndex * 8)) & 0xFFFFFFFF);
        $pdpte = $readPhys64($runtime, $pdpteAddr);
        $dumpEntry('PDPTE', $pdpteAddr, $pdpte);
        if (($pdpte & 0x1) === 0) {
            return;
        }

        if (($pdpte & 0x80) !== 0) {
            $base = $pdpte & 0x000FFFFFC0000000;
            $phys = ($base + ($linear & 0x3FFFFFFF)) & 0xFFFFFFFF;
            $runtime->option()->logger()->warning(sprintf('PFTBL: 1G page phys=0x%08X', $phys));
            return;
        }

        $pdBase = $pdpte & 0x000FFFFFFFFFF000;
        $pdeAddr = (($pdBase + ($pdIndex * 8)) & 0xFFFFFFFF);
        $pde = $readPhys64($runtime, $pdeAddr);
        $dumpEntry('PDE', $pdeAddr, $pde);
        if (($pde & 0x1) === 0) {
            return;
        }

        if (($pde & 0x80) !== 0) {
            $base = $pde & 0x000FFFFFFFFFE00000;
            $phys = ($base + ($linear & 0x1FFFFF)) & 0xFFFFFFFF;
            $runtime->option()->logger()->warning(sprintf('PFTBL: 2M page phys=0x%08X', $phys));
            return;
        }

        $ptBase = $pde & 0x000FFFFFFFFFF000;
        $pteAddr = (($ptBase + ($ptIndex * 8)) & 0xFFFFFFFF);
        $pte = $readPhys64($runtime, $pteAddr);
        $dumpEntry('PTE', $pteAddr, $pte);
        if (($pte & 0x1) === 0) {
            return;
        }

        $base = $pte & 0x000FFFFFFFFFF000;
        $phys = ($base + $pageOffset) & 0xFFFFFFFF;
        $runtime->option()->logger()->warning(sprintf('PFTBL: 4K page phys=0x%08X', $phys));
    }

    public function logExecution(RuntimeInterface $runtime, int $ipBefore, array $opcodes): void
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

        if ($this->stopAfterInsns > 0) {
            $this->stopAfterInsnsRemaining--;
            if ($this->stopAfterInsnsRemaining <= 0) {
                $runtime->option()->logger()->warning(sprintf(
                    'STOP: reached PHPME_STOP_AFTER_INSNS=%d at ip=0x%08X',
                    $this->stopAfterInsns,
                    $runtime->memory()->offset() & 0xFFFFFFFF,
                ));
                $logSampleReport();
                throw new HaltException('Stopped by PHPME_STOP_AFTER_INSNS');
            }
        }

        if ($this->stopAfterSecs <= 0) {
            return;
        }

        if ($this->stopAfterDeadline === null) {
            $this->stopAfterDeadline = microtime(true) + (float) $this->stopAfterSecs;
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
                $this->stopAfterSecs,
                $runtime->memory()->offset() & 0xFFFFFFFF,
            ));
            $logSampleReport();
            throw new HaltException('Stopped by PHPME_STOP_AFTER_SECS');
        }
    }
}
