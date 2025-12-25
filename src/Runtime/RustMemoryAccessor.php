<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use FFI;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Stream\RustFfiContext;
use PHPMachineEmulator\Stream\RustMemoryStream;

/**
 * Rust-backed high-performance memory accessor implementation.
 *
 * This class wraps the Rust MemoryAccessor implementation via FFI for
 * significantly improved performance in register and flag operations.
 */
class RustMemoryAccessor implements MemoryAccessorInterface
{
    private const VIDEO_MEMORY_MIN = 0xA0000;
    private const VIDEO_MEMORY_MAX = 0xBFFFF;
    private const TEXT_VIDEO_MIN = 0xB8000;
    private const TEXT_VIDEO_MAX = 0xBFFFF;
    private const VIDEO_TYPE_FLAG_ADDRESS = 0xFF0000;

    private RustFfiContext $ffiContext;
    private FFI $ffi;
    private ?bool $watchMsDosBoot = null;
    private bool $watchAccessConfigResolved = false;
    private ?\PHPMachineEmulator\LogicBoard\Debug\WatchAccessConfig $watchAccessConfig = null;
    private int $watchAccessHits = 0;
    private bool $watchAccessSuppressed = false;
    private bool $watchAccessAnnounced = false;
    private ?string $watchAccessConfigError = null;
    private bool $watchAccessConfigErrorAnnounced = false;
    private ?bool $stopOnWatchHit = null;
    private ?bool $dumpCallsiteOnWatchHit = null;
    private ?int $dumpCallsiteBytes = null;
    private ?bool $dumpIpOnWatchHit = null;
    private ?bool $stopOnRspZero = null;
    private ?bool $stopOnStackUnderflow = null;
    private ?int $stopOnRspBelowThreshold = null;

    /** @var FFI\CData Pointer to the Rust MemoryAccessor */
    private FFI\CData $handle;

    private function shouldWatchMsDosBoot(): bool
    {
        if ($this->watchMsDosBoot === null) {
            $this->watchMsDosBoot = $this->runtime->logicBoard()->debug()->watch()->watchMsDosBoot;
        }
        return $this->watchMsDosBoot;
    }

    private function stopOnWatchHitEnabled(): bool
    {
        if ($this->stopOnWatchHit !== null) {
            return $this->stopOnWatchHit;
        }
        $this->stopOnWatchHit = $this->runtime->logicBoard()->debug()->watch()->stopOnWatchHit;
        return $this->stopOnWatchHit;
    }

    private function dumpCallsiteOnWatchHitEnabled(): bool
    {
        if ($this->dumpCallsiteOnWatchHit !== null) {
            return $this->dumpCallsiteOnWatchHit;
        }
        $this->dumpCallsiteOnWatchHit = $this->runtime->logicBoard()->debug()->watch()->dumpCallsiteOnWatchHit;
        return $this->dumpCallsiteOnWatchHit;
    }

    private function dumpIpOnWatchHitEnabled(): bool
    {
        if ($this->dumpIpOnWatchHit !== null) {
            return $this->dumpIpOnWatchHit;
        }
        $this->dumpIpOnWatchHit = $this->runtime->logicBoard()->debug()->watch()->dumpIpOnWatchHit;
        return $this->dumpIpOnWatchHit;
    }

    private function dumpCallsiteBytes(): int
    {
        if ($this->dumpCallsiteBytes !== null) {
            return $this->dumpCallsiteBytes;
        }
        $bytes = $this->runtime->logicBoard()->debug()->watch()->dumpCallsiteBytes;
        $this->dumpCallsiteBytes = max(64, min(4096, $bytes));
        return $this->dumpCallsiteBytes;
    }

    private function readLinear32NoLog(int $linear): ?int
    {
        $isUser = $this->runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $this->runtime->context()->cpu()->isPagingEnabled();
        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        $resultValue = $this->ffi->new('uint32_t');
        $resultError = $this->ffi->new('uint32_t');

        $this->ffi->memory_accessor_read_memory_32(
            $this->handle,
            $linear & 0xFFFFFFFF,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        if (($resultError->cdata ?? 1) !== 0) {
            return null;
        }
        return (int) ($resultValue->cdata & 0xFFFFFFFF);
    }

    private function readLinearBytesNoLog(int $linear, int $length): string
    {
        $isUser = $this->runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $this->runtime->context()->cpu()->isPagingEnabled();
        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        $resultValue = $this->ffi->new('uint8_t');
        $resultError = $this->ffi->new('uint32_t');

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $this->ffi->memory_accessor_read_memory_8(
                $this->handle,
                ($linear + $i) & 0xFFFFFFFF,
                $isUser,
                $pagingEnabled,
                $linearMask,
                FFI::addr($resultValue),
                FFI::addr($resultError)
            );
            $b = (($resultError->cdata ?? 1) === 0) ? ($resultValue->cdata & 0xFF) : 0;
            $bytes .= chr($b);
        }
        return $bytes;
    }

    private function maybeDumpIpOnWatchHit(): void
    {
        if (!$this->dumpIpOnWatchHitEnabled()) {
            return;
        }

        $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
        $dumpLen = $this->dumpCallsiteBytes();
        $half = intdiv($dumpLen, 2);
        $start = max(0, ($linearIp - $half) & 0xFFFFFFFF);
        $bytes = $this->readLinearBytesNoLog($start, $dumpLen);
        $sha1 = sha1($bytes);

        $path = sprintf('debug/memdump_ip_%08X_%d.bin', $linearIp & 0xFFFFFFFF, $dumpLen);
        @file_put_contents($path, $bytes);

        $this->runtime->option()->logger()->warning(sprintf(
            'WATCH: ipdump: ip=0x%08X dump=0x%08X..+%d sha1=%s saved=%s',
            $linearIp & 0xFFFFFFFF,
            $start & 0xFFFFFFFF,
            $dumpLen,
            $sha1,
            $path,
        ));
    }

    private function maybeDumpCallsiteOnWatchHit(): void
    {
        if (!$this->dumpCallsiteOnWatchHitEnabled()) {
            return;
        }

        $cpu = $this->runtime->context()->cpu();
        $ssSelector = $this->fetch(RegisterType::SS)->asByte() & 0xFFFF;

        $ssBase = 0;
        if ($cpu->isProtectedMode()) {
            $desc = $this->segmentDescriptor($ssSelector);
            if (is_array($desc)) {
                $ssBase = (int) ($desc['base'] ?? 0);
            }
        } else {
            $ssBase = (($ssSelector << 4) & 0xFFFFF);
        }

        $ebp = $this->fetch(RegisterType::EBP)->asBytesBySize(32) & 0xFFFFFFFF;
        $retPtr = ($ssBase + (($ebp + 4) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $ret = $this->readLinear32NoLog($retPtr);
        if ($ret === null) {
            $this->runtime->option()->logger()->warning(sprintf(
                'WATCH: callsite: failed to read return address at 0x%08X (SS=0x%04X EBP=0x%08X)',
                $retPtr,
                $ssSelector,
                $ebp,
            ));
            return;
        }

        $dumpLen = $this->dumpCallsiteBytes();
        $half = intdiv($dumpLen, 2);
        $start = max(0, ($ret - $half) & 0xFFFFFFFF);
        $bytes = $this->readLinearBytesNoLog($start, $dumpLen);
        $sha1 = sha1($bytes);

        $path = sprintf('debug/memdump_callsite_%08X_%d.bin', $ret & 0xFFFFFFFF, $dumpLen);
        @file_put_contents($path, $bytes);

        $this->runtime->option()->logger()->warning(sprintf(
            'WATCH: callsite: ret=0x%08X dump=0x%08X..+%d sha1=%s saved=%s',
            $ret & 0xFFFFFFFF,
            $start & 0xFFFFFFFF,
            $dumpLen,
            $sha1,
            $path,
        ));
    }

    /**
     * Optional memory access watchpoint for debugging hard-to-reproduce corruption.
     */
    private function watchAccessConfig(): ?\PHPMachineEmulator\LogicBoard\Debug\WatchAccessConfig
    {
        if ($this->watchAccessConfigResolved) {
            return $this->watchAccessConfig;
        }
        $this->watchAccessConfigResolved = true;

        $this->watchAccessConfigError = null;
        $this->watchAccessConfigErrorAnnounced = false;

        $cfg = $this->runtime->logicBoard()->debug()->watch()->access;
        if ($cfg === null) {
            $this->watchAccessConfig = null;
            return null;
        }

        $this->watchAccessHits = 0;
        $this->watchAccessSuppressed = false;
        $this->watchAccessConfig = $cfg;
        return $this->watchAccessConfig;
    }

    private function watchAccessOverlaps(int $address, int $width): bool
    {
        $cfg = $this->watchAccessConfig();
        if ($cfg === null) {
            return false;
        }

        $bytes = max(1, intdiv(max(8, $width), 8));
        $start = $address & 0xFFFFFFFF;
        $end = ($start + ($bytes - 1)) & 0xFFFFFFFF;

        return !($end < $cfg->start || $start > $cfg->end);
    }

    private function maybeLogWatchedAccess(string $action, string $kind, int $address, int $width, ?int $value = null): void
    {
        $cfg = $this->watchAccessConfig();
        if ($cfg === null) {
            if ($this->watchAccessConfigError !== null && !$this->watchAccessConfigErrorAnnounced) {
                $this->watchAccessConfigErrorAnnounced = true;
                $this->runtime->option()->logger()->warning(sprintf('WATCH: disabled (%s)', $this->watchAccessConfigError));
            }
            return;
        }

        if ($cfg->excludeIpRanges !== []) {
            $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
            foreach ($cfg->excludeIpRanges as $r) {
                if ($linearIp >= $r['start'] && $linearIp <= $r['end']) {
                    return;
                }
            }
        }

        if ($cfg->armAfterInt13Lba !== null
            && !$this->runtime->logicBoard()->debug()->watchState()->isWatchArmed()
        ) {
            return;
        }

        if (!$this->watchAccessAnnounced) {
            $this->watchAccessAnnounced = true;
            $this->runtime->option()->logger()->warning(sprintf(
                'WATCH: enabled range=0x%X..0x%X limit=%d reads=%d writes=%d source=%s',
                $cfg->start,
                $cfg->end,
                $cfg->limit,
                $cfg->reads ? 1 : 0,
                $cfg->writes ? 1 : 0,
                $cfg->source ?? 'unknown',
            ));
        }
        if ($action === 'READ' && !$cfg->reads) {
            return;
        }
        if ($action === 'WRITE' && !$cfg->writes) {
            return;
        }
        if (!$this->watchAccessOverlaps($address, $width)) {
            return;
        }
        if ($cfg->width !== null && $width !== $cfg->width) {
            return;
        }

        if ($this->watchAccessHits >= $cfg->limit) {
            if (!$this->watchAccessSuppressed) {
                $this->watchAccessSuppressed = true;
                $this->runtime->option()->logger()->warning(sprintf(
                    'WATCH: suppressing further accesses (limit=%d) range=0x%X..0x%X',
                    $cfg->limit,
                    $cfg->start,
                    $cfg->end,
                ));
            }
            return;
        }
        $this->watchAccessHits++;

        $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
        $pm = $this->runtime->context()->cpu()->isProtectedMode() ? 1 : 0;
        $pg = $this->runtime->context()->cpu()->isPagingEnabled() ? 1 : 0;
        $cs = $this->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $ds = $this->fetch(RegisterType::DS)->asByte() & 0xFFFF;
        $es = $this->fetch(RegisterType::ES)->asByte() & 0xFFFF;
        $ss = $this->fetch(RegisterType::SS)->asByte() & 0xFFFF;
        $sp = $this->fetch(RegisterType::ESP)->asBytesBySize(16) & 0xFFFF;
        $eax = $this->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
        $ebx = $this->fetch(RegisterType::EBX)->asBytesBySize(32) & 0xFFFFFFFF;
        $ecx = $this->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;
        $edx = $this->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;
        $esi = $this->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;
        $edi = $this->fetch(RegisterType::EDI)->asBytesBySize(32) & 0xFFFFFFFF;

        $executor = $this->runtime->architectureProvider()->instructionExecutor();
        $lastIp = $executor->lastInstructionPointer() & 0xFFFFFFFF;
        $lastOpcodes = $executor->lastOpcodes();
        $lastOpcodeStr = $lastOpcodes === null
            ? 'n/a'
            : implode(' ', array_map(static fn(int $b): string => sprintf('%02X', $b & 0xFF), $lastOpcodes));
        $lastInstruction = $executor->lastInstruction();
        $lastInstructionName = $lastInstruction === null
            ? 'n/a'
            : preg_replace('/^.+\\\\(.+?)$/', '$1', get_class($lastInstruction));

        $valueStr = $value === null ? 'n/a' : sprintf('0x%X', $value & match ($width) {
            8 => 0xFF,
            16 => 0xFFFF,
            32 => 0xFFFFFFFF,
            default => 0xFFFFFFFF,
        });

        $this->runtime->option()->logger()->warning(sprintf(
            'WATCH: %s %s %d-bit addr=0x%08X value=%s CS=0x%04X DS=0x%04X ES=0x%04X SS=0x%04X SP=0x%04X EAX=0x%08X EBX=0x%08X ECX=0x%08X EDX=0x%08X ESI=0x%08X EDI=0x%08X linearIP=0x%08X PM=%d PG=%d lastIP=0x%08X lastIns=%s lastOp=%s',
            $action,
            strtoupper($kind),
            $width,
            $address & 0xFFFFFFFF,
            $valueStr,
            $cs,
            $ds,
            $es,
            $ss,
            $sp,
            $eax,
            $ebx,
            $ecx,
            $edx,
            $esi,
            $edi,
            $linearIp,
            $pm,
            $pg,
            $lastIp,
            $lastInstructionName,
            $lastOpcodeStr,
        ));

        if ($this->stopOnWatchHitEnabled()) {
            $this->maybeDumpIpOnWatchHit();
            $this->maybeDumpCallsiteOnWatchHit();
            throw new HaltException('Stopped by PHPME_STOP_ON_WATCH_HIT');
        }
    }

    private function maybeLogMsDosBootWrite(string $kind, int $address, int $width, int $value): void
    {
        if (!$this->shouldWatchMsDosBoot()) {
            return;
        }

        // Focus on the failing MS-DOS boot path:
        // - far pointer storage around 0000:06E2 (linear 0x006E2)
        // - the uninitialized call target 2020:5449 (linear 0x25649)
        $watch =
            // Common scratch/parameter area used by boot loaders and IO.SYS
            ($address >= 0x0004F0 && $address <= 0x000509) ||
            ($address >= 0x0006E0 && $address <= 0x0006E7) ||
            ($address >= 0x0025640 && $address <= 0x0025660);

        if (!$watch) {
            return;
        }

        $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
        $cs = $this->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $ds = $this->fetch(RegisterType::DS)->asByte() & 0xFFFF;
        $es = $this->fetch(RegisterType::ES)->asByte() & 0xFFFF;
        $ss = $this->fetch(RegisterType::SS)->asByte() & 0xFFFF;
        $sp = $this->fetch(RegisterType::ESP)->asBytesBySize(16) & 0xFFFF;
        $ip = ($linearIp - (($cs << 4) & 0xFFFFF)) & 0xFFFF;

        $this->runtime->option()->logger()->debug(sprintf(
            'WATCH(MSDOS): %s write%d addr=0x%05X value=0x%X at CS:IP=%04X:%04X linearIP=0x%05X DS=%04X ES=%04X SS=%04X SP=%04X',
            $kind,
            $width,
            $address & 0xFFFFFFFF,
            $value & match ($width) {
                8 => 0xFF,
                16 => 0xFFFF,
                default => 0xFFFFFFFF,
            },
            $cs,
            $ip,
            $linearIp & 0xFFFFFFFF,
            $ds,
            $es,
            $ss,
            $sp
        ));
    }

    public function __construct(
        protected RuntimeInterface $runtime,
        protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection,
        ?RustFfiContext $ffiContext = null
    ) {
        // Get the memory handle from the runtime
        $memory = $runtime->memory();

        if ($memory instanceof RustMemoryStream) {
            $memoryHandle = $memory->getHandle();
            $this->ffiContext = $ffiContext ?? $memory->ffiContext();
        } else {
            throw new \RuntimeException(
                'RustMemoryAccessor requires RustMemoryStream. ' .
                'Please use RustMemoryStream instead of MemoryStream.'
            );
        }

        $this->ffi = $this->ffiContext->ffi();

        $this->handle = $this->ffi->memory_accessor_new($memoryHandle);

        if ($this->handle === null) {
            throw new \RuntimeException('Failed to create Rust MemoryAccessor');
        }

        $this->announceWatchAccessConfigIfRequested();
    }

    private function announceWatchAccessConfigIfRequested(): void
    {
        if ($this->watchAccessAnnounced || $this->watchAccessConfigErrorAnnounced) {
            return;
        }

        if ($this->runtime->logicBoard()->debug()->watch()->access === null) {
            return;
        }

        $cfg = $this->watchAccessConfig();
        if ($cfg !== null) {
            $this->watchAccessAnnounced = true;
            $this->runtime->option()->logger()->warning(sprintf(
                'WATCH: enabled range=0x%X..0x%X limit=%d reads=%d source=%s',
                $cfg->start,
                $cfg->end,
                $cfg->limit,
                $cfg->reads ? 1 : 0,
                $cfg->source ?? 'unknown',
            ));
            return;
        }

        if ($this->watchAccessConfigError !== null && !$this->watchAccessConfigErrorAnnounced) {
            $this->watchAccessConfigErrorAnnounced = true;
            $this->runtime->option()->logger()->warning(sprintf('WATCH: disabled (%s)', $this->watchAccessConfigError));
            return;
        }

        if (!$this->watchAccessConfigErrorAnnounced) {
            $this->watchAccessConfigErrorAnnounced = true;
            $this->runtime->option()->logger()->warning('WATCH: disabled (no valid range configured)');
        }
    }

    public function __destruct()
    {
        if (isset($this->handle) && $this->ffi !== null) {
            $this->ffi->memory_accessor_free($this->handle);
        }
    }

    /**
     * Check if address is a register address (skip observer processing).
     */
    private function isRegisterAddress(int $address): bool
    {
        return ($address >= 0 && $address <= 13) || ($address >= 16 && $address <= 25);
    }

    private function shouldPostProcessLinearWrite(int $linear, int $bytes): bool
    {
        if ($bytes <= 0) {
            return false;
        }

        $start = $linear & 0xFFFFFFFF;
        $end = $start + ($bytes - 1);
        if ($end > 0xFFFFFFFF) {
            $end = 0xFFFFFFFF;
        }

        if ($start <= self::VIDEO_TYPE_FLAG_ADDRESS && $end >= self::VIDEO_TYPE_FLAG_ADDRESS) {
            return true;
        }

        return !($end < self::VIDEO_MEMORY_MIN || $start > self::VIDEO_MEMORY_MAX);
    }

    private function postProcessLinearWrite(int $linear, int $value, int $bytes): void
    {
        $base = $linear & 0xFFFFFFFF;

        for ($i = 0; $i < $bytes; $i++) {
            $addr = ($base + $i) & 0xFFFFFFFF;

            $inVideo = ($addr >= self::VIDEO_MEMORY_MIN && $addr <= self::VIDEO_MEMORY_MAX);
            $isVideoTypeFlag = ($addr === self::VIDEO_TYPE_FLAG_ADDRESS);
            if (!$inVideo && !$isVideoTypeFlag) {
                continue;
            }

            if ($addr >= self::TEXT_VIDEO_MIN && $addr <= self::TEXT_VIDEO_MAX) {
                // Text mode: only observe character bytes (even offsets), skip attribute bytes.
                if (((($addr - self::TEXT_VIDEO_MIN) & 0x1) === 1)) {
                    continue;
                }
            }

            $byte = ($value >> ($i * 8)) & 0xFF;
            $this->postProcessWhenWrote($addr, null, $byte);
        }
    }

    /**
     * Process observers after a write operation.
     */
    private function postProcessWhenWrote(int $address, int|null $previousValue, int|null $value): void
    {
        // Skip observer processing for register addresses - massive performance gain
        if ($this->isRegisterAddress($address)) {
            return;
        }

        $wroteValue = ($value ?? 0) & 0xFF;

        foreach ($this->memoryAccessorObserverCollection as $observer) {
            assert($observer instanceof MemoryAccessorObserverInterface);

            // Fast path: check address range before calling shouldMatch
            $range = $observer->addressRange();
            if ($range !== null) {
                if ($address < $range['min'] || $address > $range['max']) {
                    continue;
                }
            }

            if (!$observer->shouldMatch($this->runtime, $address, $previousValue, $wroteValue)) {
                continue;
            }

            $observer->observe(
                $this->runtime,
                $address,
                $previousValue === null ? $previousValue : ($previousValue & 0xFF),
                $wroteValue,
            );
        }
    }

    /**
     * Convert RegisterType to address.
     */
    private function asAddress(int|RegisterType $registerType): int
    {
        if ($registerType instanceof RegisterType) {
            return ($this->runtime->register())::addressBy($registerType);
        }
        return $registerType;
    }

    // ========================================
    // MemoryAccessorInterface implementation
    // ========================================

    public function allocate(int $address, int $size = 1, bool $safe = true): self
    {
        $this->ffi->memory_accessor_allocate($this->handle, $address, $size, $safe);
        return $this;
    }

    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);
        $value = $this->ffi->memory_accessor_fetch($this->handle, $address);

        // Determine stored size
        $isGpr = ($address >= 0 && $address <= 7) || ($address >= 16 && $address <= 24);
        $storedSize = $isGpr ? 64 : 16;

        return new MemoryAccessorFetchResult($value, $storedSize);
    }

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);
        $value = $this->ffi->memory_accessor_try_to_fetch($this->handle, $address);

        if ($value === -1) {
            return null;
        }

        $isGpr = ($address >= 0 && $address <= 7) || ($address >= 16 && $address <= 24);
        $storedSize = $isGpr ? 64 : 16;

        return new MemoryAccessorFetchResult($value, $storedSize);
    }

    public function increment(int|RegisterType $registerType): self
    {
        $address = $this->asAddress($registerType);
        $this->ffi->memory_accessor_increment($this->handle, $address);
        return $this;
    }

    public function add(int|RegisterType $registerType, int $value): self
    {
        $address = $this->asAddress($registerType);
        $this->ffi->memory_accessor_add($this->handle, $address, $value);
        return $this;
    }

    public function sub(int|RegisterType $registerType, int $value): self
    {
        $address = $this->asAddress($registerType);
        $this->ffi->memory_accessor_sub($this->handle, $address, $value);
        return $this;
    }

    public function decrement(int|RegisterType $registerType): self
    {
        $address = $this->asAddress($registerType);
        $this->ffi->memory_accessor_decrement($this->handle, $address);
        return $this;
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $this->maybeLogWatchedAccess('WRITE', 'bySize', $address, 16, $value ?? 0);
        $this->maybeLogMsDosBootWrite('bySize', $address, 16, $value ?? 0);
        $previousValue = $this->ffi->memory_accessor_fetch($this->handle, $address);
        $this->ffi->memory_accessor_write_16bit($this->handle, $address, $value ?? 0);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        $address = $this->asAddress($registerType);
        $this->maybeLogWatchedAccess('WRITE', 'bySize', $address, $size, $value ?? 0);
        $this->maybeLogMsDosBootWrite('bySize', $address, $size, $value ?? 0);
        $previousValue = $this->ffi->memory_accessor_fetch($this->handle, $address);
        $this->ffi->memory_accessor_write_by_size($this->handle, $address, $value ?? 0, $size);
        $this->postProcessWhenWrote($address, $previousValue, $value);

        if ($registerType instanceof RegisterType && $registerType === RegisterType::ESP) {
            if ($this->stopOnRspZero === null) {
                $this->stopOnRspZero = $this->runtime->logicBoard()->debug()->watch()->stopOnRspZero;
            }

            // Filter out stack-pop updates after an already-corrupted underflow (e.g., RSP=-8 -> 0).
            if ($this->stopOnRspZero
                && $this->runtime->context()->cpu()->isLongMode()
                && $size >= 32
                && (($value ?? 0) === 0)
                && $previousValue > 0x1000
            ) {
                $ip = $this->runtime->memory()->offset() & 0xFFFFFFFF;
                $cs = $this->fetch(RegisterType::CS)->asByte() & 0xFFFF;
                $ss = $this->fetch(RegisterType::SS)->asByte() & 0xFFFF;
                $this->runtime->option()->logger()->warning(sprintf(
                    'STOP: RSP/ESP written as zero at ip=0x%08X CS=0x%04X SS=0x%04X size=%d',
                    $ip,
                    $cs,
                    $ss,
                    $size,
                ));
                throw new HaltException('Stopped by PHPME_STOP_ON_RSP_ZERO');
            }

            if ($this->stopOnRspBelowThreshold === null) {
                $this->stopOnRspBelowThreshold = $this->runtime->logicBoard()->debug()->watch()->stopOnRspBelowThreshold;
            }

            $threshold = $this->stopOnRspBelowThreshold;
            if ($threshold !== null
                && $threshold > 0
                && $this->runtime->context()->cpu()->isLongMode()
                && $size >= 32
            ) {
                $newSp = $value ?? 0;
                if ($newSp >= 0 && $newSp < $threshold && $previousValue > $threshold) {
                    $ip = $this->runtime->memory()->offset() & 0xFFFFFFFF;
                    $cs = $this->fetch(RegisterType::CS)->asByte() & 0xFFFF;
                    $ss = $this->fetch(RegisterType::SS)->asByte() & 0xFFFF;
                    $this->runtime->option()->logger()->warning(sprintf(
                        'STOP: RSP/ESP dropped below 0x%X at ip=0x%08X CS=0x%04X SS=0x%04X prev=0x%X new=0x%X size=%d',
                        $threshold & 0xFFFFFFFF,
                        $ip,
                        $cs,
                        $ss,
                        $previousValue & 0xFFFFFFFF,
                        $newSp & 0xFFFFFFFF,
                        $size,
                    ));
                    throw new HaltException('Stopped by PHPME_STOP_ON_RSP_BELOW');
                }
            }
        }
        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $this->ffi->memory_accessor_write_to_high_bit($this->handle, $address, $value ?? 0);
        return $this;
    }

    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $this->ffi->memory_accessor_write_to_low_bit($this->handle, $address, $value ?? 0);
        return $this;
    }

    public function updateFlags(int|null $value, int $size = 16): self
    {
        if ($value === null) {
            $this->ffi->memory_accessor_set_zero_flag($this->handle, true);
            $this->ffi->memory_accessor_set_sign_flag($this->handle, false);
            $this->ffi->memory_accessor_set_overflow_flag($this->handle, false);
            $this->ffi->memory_accessor_set_parity_flag($this->handle, true);
            return $this;
        }

        $this->ffi->memory_accessor_update_flags($this->handle, $value, $size);
        return $this;
    }

    public function setCarryFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_carry_flag($this->handle, $which);
        return $this;
    }

    public function pop(int|RegisterType $registerType, int $size = 16): MemoryAccessorFetchResultInterface
    {
        // Stack operations still need PHP-side handling for complex logic
        // This is a simplified version - full implementation would need more work
        $address = $this->asAddress($registerType);

        if ($registerType instanceof RegisterType && $registerType === RegisterType::ESP) {
            $stackAddrSize = $this->stackAddressSize();
            $sp = $this->fetch(RegisterType::ESP)->asBytesBySize($stackAddrSize);
            $bytes = intdiv($size, 8);
            $address = $this->stackLinearAddress($sp, $stackAddrSize, false);

            // Read value from stack
            $value = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $value |= $this->ffi->memory_accessor_read_from_memory($this->handle, $address + $i) << ($i * 8);
            }

            // Update SP
            $mask = $this->stackPointerMask($stackAddrSize);
            $newSp = ($sp + $bytes) & $mask;
            $this->writeBySize(RegisterType::ESP, $newSp, $stackAddrSize);

            return new MemoryAccessorFetchResult($value, $size, alreadyDecoded: true);
        }

        $fetchResult = $this->fetch($address)->asBytesBySize();
        $this->writeBySize($address, $fetchResult >> $size);

        return new MemoryAccessorFetchResult(
            $fetchResult & ((1 << $size) - 1)
        );
    }

    public function push(int|RegisterType $registerType, int|null $value, int $size = 16): self
    {
        if ($registerType instanceof RegisterType && $registerType === RegisterType::ESP) {
            $stackAddrSize = $this->stackAddressSize();
            $sp = $this->fetch(RegisterType::ESP)->asBytesBySize($stackAddrSize);
            $bytes = intdiv($size, 8);
            $mask = $this->stackPointerMask($stackAddrSize);

            if ($this->stopOnStackUnderflow === null) {
                $this->stopOnStackUnderflow = $this->runtime->logicBoard()->debug()->watch()->stopOnStackUnderflow;
            }
            if ($this->stopOnStackUnderflow && $this->runtime->context()->cpu()->isLongMode() && $stackAddrSize === 64 && $sp >= 0 && $sp < $bytes) {
                $ip = $this->runtime->memory()->offset() & 0xFFFFFFFF;
                $cs = $this->fetch(RegisterType::CS)->asByte() & 0xFFFF;
                $ss = $this->fetch(RegisterType::SS)->asByte() & 0xFFFF;
                $this->runtime->option()->logger()->warning(sprintf(
                    'STOP: stack underflow on push at ip=0x%08X CS=0x%04X SS=0x%04X RSP=0x%016X bytes=%d size=%d',
                    $ip,
                    $cs,
                    $ss,
                    $sp,
                    $bytes,
                    $size,
                ));
                throw new HaltException('Stopped by PHPME_STOP_ON_STACK_UNDERFLOW');
            }

            $newSp = ($sp - $bytes) & $mask;
            $address = $this->stackLinearAddress($newSp, $stackAddrSize, true);

            $this->writeBySize(RegisterType::ESP, $newSp, $stackAddrSize);
            $this->allocate($address, $bytes, safe: false);

            $masked = $value & $this->valueMask($size);
            for ($i = 0; $i < $bytes; $i++) {
                $this->writeBySize($address + $i, ($masked >> ($i * 8)) & 0xFF, 8);
            }

            return $this;
        }

        $address = $this->asAddress($registerType);
        $fetchResult = $this->fetch($address)->asBytesBySize();
        $value = $value & ((1 << $size) - 1);
        $this->writeBySize($address, ($fetchResult << $size) + $value);

        return $this;
    }

    public function readControlRegister(int $index): int
    {
        return $this->ffi->memory_accessor_read_control_register($this->handle, $index);
    }

    public function writeControlRegister(int $index, int $value): void
    {
        $previous = $this->ffi->memory_accessor_read_control_register($this->handle, $index);
        $this->ffi->memory_accessor_write_control_register($this->handle, $index, $value);

        // Mode changes can alter instruction decoding/execution semantics
        if ($index === 0 && $previous !== $value) {
            $this->runtime->architectureProvider()->instructionExecutor()->invalidateCaches();
        }
    }

    public function shouldZeroFlag(): bool
    {
        return $this->ffi->memory_accessor_zero_flag($this->handle);
    }

    public function shouldSignFlag(): bool
    {
        return $this->ffi->memory_accessor_sign_flag($this->handle);
    }

    public function shouldOverflowFlag(): bool
    {
        return $this->ffi->memory_accessor_overflow_flag($this->handle);
    }

    public function shouldCarryFlag(): bool
    {
        return $this->ffi->memory_accessor_carry_flag($this->handle);
    }

    public function shouldParityFlag(): bool
    {
        return $this->ffi->memory_accessor_parity_flag($this->handle);
    }

    public function shouldAuxiliaryCarryFlag(): bool
    {
        return $this->ffi->memory_accessor_auxiliary_carry_flag($this->handle);
    }

    public function shouldDirectionFlag(): bool
    {
        return $this->ffi->memory_accessor_direction_flag($this->handle);
    }

    public function shouldInterruptFlag(): bool
    {
        return $this->ffi->memory_accessor_interrupt_flag($this->handle);
    }

    public function setZeroFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_zero_flag($this->handle, $which);
        return $this;
    }

    public function setSignFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_sign_flag($this->handle, $which);
        return $this;
    }

    public function setOverflowFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_overflow_flag($this->handle, $which);
        return $this;
    }

    public function setParityFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_parity_flag($this->handle, $which);
        return $this;
    }

    public function setAuxiliaryCarryFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_auxiliary_carry_flag($this->handle, $which);
        return $this;
    }

    public function setDirectionFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_direction_flag($this->handle, $which);
        return $this;
    }

    public function setInterruptFlag(bool $which): self
    {
        $this->ffi->memory_accessor_set_interrupt_flag($this->handle, $which);
        return $this;
    }

    public function writeEfer(int $value): void
    {
        $this->ffi->memory_accessor_write_efer($this->handle, $value);
    }

    /**
     * Read EFER value.
     */
    public function readEfer(): int
    {
        return $this->ffi->memory_accessor_read_efer($this->handle);
    }

    /**
     * Write a raw byte to memory.
     */
    public function writeRawByte(int $address, int $value): self
    {
        $this->maybeLogWatchedAccess('WRITE', 'raw', $address, 8, $value);
        $this->maybeLogMsDosBootWrite('raw', $address, 8, $value);
        $previousValue = $this->ffi->memory_accessor_read_raw_byte($this->handle, $address);
        $this->ffi->memory_accessor_write_raw_byte($this->handle, $address, $value & 0xFF);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    /**
     * Read a raw byte from memory.
     */
    public function readRawByte(int $address): ?int
    {
        $value = $this->ffi->memory_accessor_read_raw_byte($this->handle, $address);
        $this->maybeLogWatchedAccess('READ', 'raw', $address, 8, $value);
        return $value;
    }

    /**
     * Set instruction fetch flag.
     */
    public function setInstructionFetch(bool $flag): self
    {
        $this->ffi->memory_accessor_set_instruction_fetch($this->handle, $flag);
        return $this;
    }

    /**
     * Get instruction fetch flag.
     */
    public function shouldInstructionFetch(): bool
    {
        return $this->ffi->memory_accessor_instruction_fetch($this->handle);
    }

    /**
     * Get the Rust MemoryAccessor handle.
     */
    public function getHandle(): FFI\CData
    {
        return $this->handle;
    }

    /**
     * Read 8-bit value from physical memory.
     */
    public function readPhysical8(int $address): int
    {
        return $this->ffi->memory_accessor_read_physical_8($this->handle, $address);
    }

    /**
     * Read 16-bit value from physical memory.
     */
    public function readPhysical16(int $address): int
    {
        return $this->ffi->memory_accessor_read_physical_16($this->handle, $address);
    }

    /**
     * Read 32-bit value from physical memory.
     */
    public function readPhysical32(int $address): int
    {
        return $this->ffi->memory_accessor_read_physical_32($this->handle, $address);
    }

    /**
     * Read 64-bit value from physical memory.
     */
    public function readPhysical64(int $address): int
    {
        return $this->ffi->memory_accessor_read_physical_64($this->handle, $address);
    }

    /**
     * Write 32-bit value to physical memory.
     */
    public function writePhysical32(int $address, int $value): void
    {
        $this->maybeLogWatchedAccess('WRITE', 'phys', $address, 32, $value);
        $this->ffi->memory_accessor_write_physical_32($this->handle, $address, $value);
    }

    /**
     * Write 64-bit value to physical memory.
     */
    public function writePhysical64(int $address, int $value): void
    {
        $this->maybeLogWatchedAccess('WRITE', 'phys', $address, 64, $value);
        $this->ffi->memory_accessor_write_physical_64($this->handle, $address, $value);
    }

    /**
     * Translate linear address to physical address through paging.
     * Returns [physical_address, error_code].
     * error_code is 0 on success, 0xFFFFFFFF for MMIO, or (vector << 16) | fault_code for page fault.
     */
    public function translateLinear(int $linear, bool $isWrite, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultPhysical = $this->ffi->new('uint64_t');
        $resultError = $this->ffi->new('uint32_t');

        $this->ffi->memory_accessor_translate_linear(
            $this->handle,
            $linear,
            $isWrite,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultPhysical),
            FFI::addr($resultError)
        );

        return [$resultPhysical->cdata, $resultError->cdata];
    }

    /**
     * Compute stack linear address honoring segment base/limit and cached descriptors.
     */
    private function stackLinearAddress(int $sp, int $stackAddrSize, bool $isWrite = false): int
    {
        $cpu = $this->runtime->context()->cpu();
        $ssSelector = $this->fetch(RegisterType::SS)->asByte();
        $mask = $this->stackPointerMask($stackAddrSize);
        $linearMask = $cpu->isLongMode() ? 0x0000FFFFFFFFFFFF : ($cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF);
        $isUser = $cpu->cpl() === 3;
        $pagingEnabled = $cpu->isPagingEnabled();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $linear = ($sp & $mask) & $linearMask;
            [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
            return $error === 0 ? $physical : $linear;
        }

        if ($cpu->isProtectedMode()) {
            $descriptor = $this->segmentDescriptor($ssSelector);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $cpl = $cpu->cpl();
                $rpl = $ssSelector & 0x3;
                $dpl = $descriptor['dpl'] ?? 0;
                $isWritable = ($descriptor['type'] & 0x2) !== 0;
                $isExecutable = $descriptor['executable'] ?? false;
                if ($isExecutable || !$isWritable || $dpl !== $cpl || $rpl !== $cpl) {
                    $linear = ($sp & $mask) & $linearMask;
                } else {
                    if (($sp & $mask) > $descriptor['limit']) {
                        throw new FaultException(0x0C, $ssSelector, 'Stack limit exceeded');
                    }
                    $linear = ($descriptor['base'] + ($sp & $mask)) & $linearMask;
                }
                [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
                return $error === 0 ? $physical : $linear;
            }
            $linear = ($sp & $mask) & $linearMask;
            [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
            return $error === 0 ? $physical : $linear;
        }

        $cached = $this->runtime->context()->cpu()->getCachedSegmentDescriptor(RegisterType::SS);
        if ($cached !== null) {
            $effSp = $sp & $mask;
            $limit = $cached['limit'] ?? $mask;
            if ($effSp > $limit) {
                $effSp = $sp & 0xFFFF;
            }
            $base = $cached['base'] ?? (($ssSelector << 4) & 0xFFFFF);
            $linear = ($base + $effSp) & $linearMask;
        } else {
            $linear = ((($ssSelector << 4) & 0xFFFFF) + ($sp & $mask)) & $linearMask;
        }

        [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
        return $error === 0 ? $physical : $linear;
    }

    private function stackAddressSize(): int
    {
        $cpu = $this->runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            return 64;
        }

        $cached = method_exists($cpu, 'getCachedSegmentDescriptor')
            ? $cpu->getCachedSegmentDescriptor(RegisterType::SS)
            : null;

        $default = is_array($cached) ? ($cached['default'] ?? null) : null;
        if ($default === 32 || $default === 16 || $default === 64) {
            return (int) $default;
        }

        if ($cpu->isProtectedMode()) {
            return $cpu->defaultOperandSize() === 32 ? 32 : 16;
        }

        return 16;
    }

    private function stackPointerMask(int $stackAddrSize): int
    {
        return match ($stackAddrSize) {
            32 => 0xFFFFFFFF,
            16 => 0xFFFF,
            default => -1,
        };
    }

    private function valueMask(int $valueSize): int
    {
        return match ($valueSize) {
            32 => 0xFFFFFFFF,
            16 => 0xFFFF,
            8 => 0xFF,
            default => ($valueSize >= 63) ? -1 : ((1 << $valueSize) - 1),
        };
    }

    /**
     * Read a segment descriptor from GDT/LDT.
     */
    private function segmentDescriptor(int $selector): ?array
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $this->runtime->context()->cpu()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
        } else {
            $gdtr = $this->runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
        }

        $index = ($selector >> 3) & 0x1FFF;
        $offset = $base + ($index * 8);

        if ($offset + 7 > $base + $limit) {
            return null;
        }

        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $isUser = $this->runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $this->runtime->context()->cpu()->isPagingEnabled();

        // Fast path: fetch full 64-bit descriptor in one FFI call.
        $desc64 = $this->ffi->new('uint64_t');
        $descErr = $this->ffi->new('uint32_t');
        $this->ffi->memory_accessor_read_memory_64(
            $this->handle,
            $offset,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($desc64),
            FFI::addr($descErr)
        );

        if ($descErr->cdata === 0) {
            $val = $desc64->cdata;
            $b0 = $val & 0xFF;
            $b1 = ($val >> 8) & 0xFF;
            $b2 = ($val >> 16) & 0xFF;
            $b3 = ($val >> 24) & 0xFF;
            $b4 = ($val >> 32) & 0xFF;
            $b5 = ($val >> 40) & 0xFF;
            $b6 = ($val >> 48) & 0xFF;
            $b7 = ($val >> 56) & 0xFF;
        } else {
            // Fallback: byte-by-byte read (should rarely happen).
            $b0 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset);
            $b1 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 1);
            $b2 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 2);
            $b3 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 3);
            $b4 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 4);
            $b5 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 5);
            $b6 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 6);
            $b7 = $this->ffi->memory_accessor_read_from_memory($this->handle, $offset + 7);
        }

        $limitLow = $b0 | ($b1 << 8);
        $limitHigh = $b6 & 0x0F;
        $fullLimit = $limitLow | ($limitHigh << 16);
        if (($b6 & 0x80) !== 0) {
            $fullLimit = ($fullLimit << 12) | 0xFFF;
        }

        $baseAddr = $b2 | ($b3 << 8) | ($b4 << 16) | ($b7 << 24);
        $present = ($b5 & 0x80) !== 0;
        $dpl = ($b5 >> 5) & 0x3;
        $type = $b5 & 0x0F;
        $executable = ($type & 0x08) !== 0;

        return [
            'base' => $baseAddr & 0xFFFFFFFF,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
            'dpl' => $dpl,
            'type' => $type,
            'executable' => $executable,
        ];
    }

    /**
     * Check if address is in MMIO range.
     */
    public function isMmioAddress(int $address): bool
    {
        return $this->ffi->memory_accessor_is_mmio_address($address);
    }

    /**
     * Read 8-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory8(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = $this->ffi->new('uint8_t');
        $resultError = $this->ffi->new('uint32_t');

        $this->ffi->memory_accessor_read_memory_8(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        $value = $resultValue->cdata;
        $this->maybeLogWatchedAccess('READ', 'linear', $linear, 8, $value);
        return [$value, $resultError->cdata];
    }

    /**
     * Read 16-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory16(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = $this->ffi->new('uint16_t');
        $resultError = $this->ffi->new('uint32_t');

        $this->ffi->memory_accessor_read_memory_16(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        $value = $resultValue->cdata;
        $this->maybeLogWatchedAccess('READ', 'linear', $linear, 16, $value);
        return [$value, $resultError->cdata];
    }

    /**
     * Read 32-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory32(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = $this->ffi->new('uint32_t');
        $resultError = $this->ffi->new('uint32_t');

        $this->ffi->memory_accessor_read_memory_32(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        $value = $resultValue->cdata;
        $this->maybeLogWatchedAccess('READ', 'linear', $linear, 32, $value);
        return [$value, $resultError->cdata];
    }

    /**
     * Read 64-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory64(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = $this->ffi->new('uint64_t');
        $resultError = $this->ffi->new('uint32_t');

        $this->ffi->memory_accessor_read_memory_64(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        $value = $resultValue->cdata;
        $this->maybeLogWatchedAccess('READ', 'linear', $linear, 64, (int) $value);
        return [$value, $resultError->cdata];
    }

    /**
     * Write 8-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory8(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 8, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 8, $value);
        $error = $this->ffi->memory_accessor_write_memory_8(
            $this->handle,
            $linear,
            $value & 0xFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
        if ($error === 0 && $this->shouldPostProcessLinearWrite($linear, 1)) {
            $this->postProcessLinearWrite($linear, $value & 0xFF, 1);
        }
        return $error;
    }

    /**
     * Write 16-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory16(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 16, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 16, $value);
        $masked = $value & 0xFFFF;
        $error = $this->ffi->memory_accessor_write_memory_16(
            $this->handle,
            $linear,
            $masked,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
        if ($error === 0 && $this->shouldPostProcessLinearWrite($linear, 2)) {
            $this->postProcessLinearWrite($linear, $masked, 2);
        }
        return $error;
    }

    /**
     * Write 32-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory32(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 32, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 32, $value);
        $masked = $value & 0xFFFFFFFF;
        $error = $this->ffi->memory_accessor_write_memory_32(
            $this->handle,
            $linear,
            $masked,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
        if ($error === 0 && $this->shouldPostProcessLinearWrite($linear, 4)) {
            $this->postProcessLinearWrite($linear, $masked, 4);
        }
        return $error;
    }

    /**
     * Write 64-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory64(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 64, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 64, $value);
        $error = $this->ffi->memory_accessor_write_memory_64(
            $this->handle,
            $linear,
            $value,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
        if ($error === 0 && $this->shouldPostProcessLinearWrite($linear, 8)) {
            $this->postProcessLinearWrite($linear, $value, 8);
        }
        return $error;
    }

    /**
     * Write 16-bit value to physical memory.
     */
    public function writePhysical16(int $address, int $value): void
    {
        $this->maybeLogWatchedAccess('WRITE', 'phys', $address, 16, $value);
        $this->maybeLogMsDosBootWrite('phys', $address, 16, $value);
        $this->ffi->memory_accessor_write_physical_16($this->handle, $address, $value & 0xFFFF);
    }
}
