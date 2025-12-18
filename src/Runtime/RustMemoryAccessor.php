<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use FFI;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Debug\DebugState;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Stream\RustMemoryStream;

/**
 * Rust-backed high-performance memory accessor implementation.
 *
 * This class wraps the Rust MemoryAccessor implementation via FFI for
 * significantly improved performance in register and flag operations.
 */
class RustMemoryAccessor implements MemoryAccessorInterface
{
    private static ?FFI $ffi = null;
    private static ?bool $watchMsDosBoot = null;
    private static bool $watchAccessConfigResolved = false;
    /** @var array{start:int,end:int,limit:int,reads:bool,writes:bool,width:?int,excludeIpRanges?:array<int,array{start:int,end:int}>,source?:string,armAfterInt13Lba?:int}|null */
    private static ?array $watchAccessConfig = null;
    private static int $watchAccessHits = 0;
    private static bool $watchAccessSuppressed = false;
    private static bool $watchAccessAnnounced = false;
    private static ?string $watchAccessConfigError = null;
    private static bool $watchAccessConfigErrorAnnounced = false;
    private static ?bool $stopOnWatchHit = null;
    private static ?bool $dumpCallsiteOnWatchHit = null;
    private static ?int $dumpCallsiteBytes = null;

    /** @var FFI\CData Pointer to the Rust MemoryAccessor */
    private FFI\CData $handle;

    private static function shouldWatchMsDosBoot(): bool
    {
        if (self::$watchMsDosBoot === null) {
            $env = getenv('PHPME_WATCH_MSDOS_BOOT');
            self::$watchMsDosBoot = $env !== false && $env !== '' && $env !== '0';
        }
        return self::$watchMsDosBoot;
    }

    private static function parseEnvInt(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '0x') || str_starts_with($trimmed, '0X')) {
            $hex = substr($trimmed, 2);
            if ($hex === '' || preg_match('/[^0-9a-fA-F]/', $hex)) {
                return null;
            }
            return (int) hexdec($hex);
        }
        if (preg_match('/^-?\\d+$/', $trimmed) !== 1) {
            return null;
        }
        return (int) $trimmed;
    }

    private static function parseEnvBool(string $value): bool
    {
        $trimmed = strtolower(trim($value));
        return !($trimmed === '' || $trimmed === '0' || $trimmed === 'false' || $trimmed === 'no' || $trimmed === 'off');
    }

    private static function stopOnWatchHitEnabled(): bool
    {
        if (self::$stopOnWatchHit !== null) {
            return self::$stopOnWatchHit;
        }
        $env = getenv('PHPME_STOP_ON_WATCH_HIT');
        self::$stopOnWatchHit = $env !== false && self::parseEnvBool($env);
        return self::$stopOnWatchHit;
    }

    private static function dumpCallsiteOnWatchHitEnabled(): bool
    {
        if (self::$dumpCallsiteOnWatchHit !== null) {
            return self::$dumpCallsiteOnWatchHit;
        }
        $env = getenv('PHPME_DUMP_CALLSITE_ON_WATCH_HIT');
        self::$dumpCallsiteOnWatchHit = $env !== false && self::parseEnvBool($env);
        return self::$dumpCallsiteOnWatchHit;
    }

    private static function dumpCallsiteBytes(): int
    {
        if (self::$dumpCallsiteBytes !== null) {
            return self::$dumpCallsiteBytes;
        }
        $env = getenv('PHPME_DUMP_CALLSITE_BYTES');
        $parsed = ($env !== false) ? (self::parseEnvInt($env) ?? 512) : 512;
        self::$dumpCallsiteBytes = max(64, min(4096, $parsed));
        return self::$dumpCallsiteBytes;
    }

    private function readLinear32NoLog(int $linear): ?int
    {
        $isUser = $this->runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $this->runtime->context()->cpu()->isPagingEnabled();
        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        $resultValue = self::$ffi->new('uint32_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_32(
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

        $resultValue = self::$ffi->new('uint8_t');
        $resultError = self::$ffi->new('uint32_t');

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            self::$ffi->memory_accessor_read_memory_8(
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

    private function maybeDumpCallsiteOnWatchHit(): void
    {
        if (!self::dumpCallsiteOnWatchHitEnabled()) {
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

        $dumpLen = self::dumpCallsiteBytes();
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
     * @return array{start:int,end:int}|null
     */
    private static function parseWatchExpr(string $expr, int $defaultLen = 1): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        $sep = str_contains($expr, '-') ? '-' : (str_contains($expr, ':') ? ':' : null);
        if ($sep !== null) {
            [$a, $b] = array_map('trim', explode($sep, $expr, 2));
            $aVal = self::parseEnvInt($a);
            $bVal = self::parseEnvInt($b);
            if ($aVal === null || $bVal === null) {
                return null;
            }
            return [
                'start' => min($aVal, $bVal),
                'end' => max($aVal, $bVal),
            ];
        }

        $aVal = self::parseEnvInt($expr);
        if ($aVal === null) {
            return null;
        }
        $len = max(1, $defaultLen);
        return [
            'start' => $aVal,
            'end' => $aVal + ($len - 1),
        ];
    }

    /**
     * @return array<int,array{start:int,end:int}>
     */
    private static function parseWatchExprList(string $expr): array
    {
        $trimmed = trim($expr);
        if ($trimmed === '' || $trimmed === '0') {
            return [];
        }

        $parts = preg_split('/[\\s,]+/', $trimmed);
        if (!is_array($parts)) {
            return [];
        }

        $ranges = [];
        foreach ($parts as $part) {
            $p = trim((string) $part);
            if ($p === '') {
                continue;
            }
            $parsed = self::parseWatchExpr($p, 1);
            if ($parsed === null) {
                continue;
            }
            $ranges[] = [
                'start' => $parsed['start'] & 0xFFFFFFFF,
                'end' => $parsed['end'] & 0xFFFFFFFF,
            ];
        }
        return $ranges;
    }

    /**
     * @return array{addr:?string,len:?int,limit:?int,reads:?bool,writes:?bool,grub:?bool}|null
     */
    private static function watchAccessFileConfig(): ?array
    {
        $basePath = dirname(__DIR__, 2);
        $path = $basePath . '/debug/watch_access.txt';
        if (!is_file($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return null;
        }

        $addr = null;
        $len = null;
        $limit = null;
        $reads = null;
        $writes = null;
        $width = null;
        $grub = null;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $key = strtolower($key);
                switch ($key) {
                    case 'addr':
                    case 'address':
                    case 'range':
                        $addr = $value !== '' ? $value : null;
                        break;
                    case 'len':
                    case 'length':
                        $parsed = self::parseEnvInt($value);
                        if ($parsed !== null) {
                            $len = max(1, $parsed);
                        }
                        break;
                    case 'limit':
                        $parsed = self::parseEnvInt($value);
                        if ($parsed !== null) {
                            $limit = max(1, $parsed);
                        }
                        break;
                    case 'reads':
                    case 'read':
                        $reads = self::parseEnvBool($value);
                        break;
                    case 'writes':
                    case 'write':
                        $writes = self::parseEnvBool($value);
                        break;
                    case 'width':
                        $parsed = self::parseEnvInt($value);
                        if ($parsed !== null) {
                            $width = $parsed;
                        }
                        break;
                    case 'grub_free_magic':
                    case 'grub':
                        $grub = self::parseEnvBool($value);
                        break;
                }
                continue;
            }

            if ($addr === null) {
                $addr = $line;
            }
        }

        return [
            'addr' => $addr,
            'len' => $len,
            'limit' => $limit,
            'reads' => $reads,
            'writes' => $writes,
            'width' => $width,
            'grub' => $grub,
        ];
    }

    /**
     * Optional memory access watchpoint for debugging hard-to-reproduce corruption.
     *
     * Env vars:
     * - PHPME_WATCH_GRUB_FREE_MAGIC=1  (default range around 0x7FFFE820)
     * - PHPME_WATCH_ADDR=0xADDR or 0xSTART-0xEND / 0xSTART:0xEND
     * - PHPME_WATCH_LEN=0xLEN (only for single address form)
     * - PHPME_WATCH_LIMIT=64 (max logs)
     * - PHPME_WATCH_READ=1 (also log reads)
     * - PHPME_WATCH_WRITE=0 (suppress write logs)
     * - PHPME_WATCH_WIDTH=32 (only log matching widths)
     *
     * File fallback (useful when env vars can't be passed to GUI launches):
     * - debug/watch_access.txt (see watchAccessFileConfig for supported keys)
     *
     * @return array{start:int,end:int,limit:int,reads:bool,writes:bool,width:?int,source?:string}|null
     */
    private static function watchAccessConfig(): ?array
    {
        if (self::$watchAccessConfigResolved) {
            return self::$watchAccessConfig;
        }
        self::$watchAccessConfigResolved = true;

        self::$watchAccessConfigError = null;
        self::$watchAccessConfigErrorAnnounced = false;

        $fileCfg = self::watchAccessFileConfig();

        $start = null;
        $end = null;
        $source = null;

        $addrEnv = getenv('PHPME_WATCH_ADDR');
        if ($addrEnv !== false && trim($addrEnv) !== '') {
            $lenEnv = getenv('PHPME_WATCH_LEN');
            $len = $lenEnv !== false ? (self::parseEnvInt($lenEnv) ?? 1) : 1;
            $parsed = self::parseWatchExpr($addrEnv, $len);
            if ($parsed !== null) {
                $start = $parsed['start'];
                $end = $parsed['end'];
                $source = 'env';
            } else {
                self::$watchAccessConfigError = sprintf('PHPME_WATCH_ADDR is invalid: %s', trim($addrEnv));
            }
        }

        if ($start === null || $end === null) {
            $grubEnv = getenv('PHPME_WATCH_GRUB_FREE_MAGIC');
            if ($grubEnv !== false && $grubEnv !== '' && $grubEnv !== '0') {
                $start = 0x7FFFE820;
                $end = $start + 0x1F;
                $source = 'env(grub)';
            }
        }

        if (($start === null || $end === null) && $fileCfg !== null) {
            if (($fileCfg['grub'] ?? false) === true) {
                $start = 0x7FFFE820;
                $end = $start + 0x1F;
                $source = 'file(grub)';
            } elseif (($fileCfg['addr'] ?? null) !== null) {
                $parsed = self::parseWatchExpr((string) $fileCfg['addr'], (int) ($fileCfg['len'] ?? 1));
                if ($parsed !== null) {
                    $start = $parsed['start'];
                    $end = $parsed['end'];
                    $source = 'file';
                } else {
                    self::$watchAccessConfigError = sprintf('debug/watch_access.txt addr is invalid: %s', (string) $fileCfg['addr']);
                }
            }
        }

        if ($start === null || $end === null) {
            self::$watchAccessConfig = null;
            return null;
        }

        $limitEnv = getenv('PHPME_WATCH_LIMIT');
        if ($limitEnv !== false && trim($limitEnv) !== '') {
            $limit = self::parseEnvInt($limitEnv) ?? 64;
        } else {
            $limit = (int) ($fileCfg['limit'] ?? 64);
        }
        $limit = max(1, $limit);

        $readsEnv = getenv('PHPME_WATCH_READ');
        if ($readsEnv !== false && trim($readsEnv) !== '') {
            $reads = self::parseEnvBool($readsEnv);
        } else {
            $reads = (bool) ($fileCfg['reads'] ?? false);
        }

        $writesEnv = getenv('PHPME_WATCH_WRITE');
        if ($writesEnv !== false && trim($writesEnv) !== '') {
            $writes = self::parseEnvBool($writesEnv);
        } else {
            $writes = (bool) ($fileCfg['writes'] ?? true);
        }

        $widthEnv = getenv('PHPME_WATCH_WIDTH');
        if ($widthEnv !== false && trim($widthEnv) !== '') {
            $width = self::parseEnvInt($widthEnv);
        } else {
            $width = (int) ($fileCfg['width'] ?? 0);
        }
        if (!in_array($width, [8, 16, 32, 64], true)) {
            $width = null;
        }

        $excludeIpRanges = [];
        $excludeIpEnv = getenv('PHPME_WATCH_EXCLUDE_IP');
        if ($excludeIpEnv !== false) {
            $excludeIpRanges = self::parseWatchExprList($excludeIpEnv);
        }

        self::$watchAccessHits = 0;
        self::$watchAccessSuppressed = false;
        self::$watchAccessConfig = [
            'start' => $start & 0xFFFFFFFF,
            'end' => $end & 0xFFFFFFFF,
            'limit' => $limit,
            'reads' => $reads,
            'writes' => $writes,
            'width' => $width,
        ];
        if ($excludeIpRanges !== []) {
            self::$watchAccessConfig['excludeIpRanges'] = $excludeIpRanges;
        }
        if ($source !== null) {
            self::$watchAccessConfig['source'] = $source;
        }

        $armEnv = getenv('PHPME_WATCH_ARM_AFTER_INT13_LBA');
        if ($armEnv !== false && trim($armEnv) !== '' && trim($armEnv) !== '0') {
            $arm = self::parseEnvInt($armEnv);
            if ($arm !== null) {
                self::$watchAccessConfig['armAfterInt13Lba'] = $arm;
                DebugState::setWatchArmAfterInt13Lba($arm);
            }
        }

        return self::$watchAccessConfig;
    }

    private function watchAccessOverlaps(int $address, int $width): bool
    {
        $cfg = self::watchAccessConfig();
        if ($cfg === null) {
            return false;
        }

        $bytes = max(1, intdiv(max(8, $width), 8));
        $start = $address & 0xFFFFFFFF;
        $end = ($start + ($bytes - 1)) & 0xFFFFFFFF;

        return !($end < $cfg['start'] || $start > $cfg['end']);
    }

    private function maybeLogWatchedAccess(string $action, string $kind, int $address, int $width, ?int $value = null): void
    {
        $cfg = self::watchAccessConfig();
        if ($cfg === null) {
            if (self::$watchAccessConfigError !== null && !self::$watchAccessConfigErrorAnnounced) {
                self::$watchAccessConfigErrorAnnounced = true;
                $this->runtime->option()->logger()->warning(sprintf('WATCH: disabled (%s)', self::$watchAccessConfigError));
            }
            return;
        }

        if (isset($cfg['excludeIpRanges']) && $cfg['excludeIpRanges'] !== []) {
            $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
            foreach ($cfg['excludeIpRanges'] as $r) {
                if ($linearIp >= $r['start'] && $linearIp <= $r['end']) {
                    return;
                }
            }
        }

        if (isset($cfg['armAfterInt13Lba']) && !DebugState::isWatchArmed()) {
            return;
        }

        if (!self::$watchAccessAnnounced) {
            self::$watchAccessAnnounced = true;
            $this->runtime->option()->logger()->warning(sprintf(
                'WATCH: enabled range=0x%X..0x%X limit=%d reads=%d writes=%d source=%s',
                $cfg['start'],
                $cfg['end'],
                $cfg['limit'],
                $cfg['reads'] ? 1 : 0,
                $cfg['writes'] ? 1 : 0,
                $cfg['source'] ?? 'unknown',
            ));
        }
        if ($action === 'READ' && !$cfg['reads']) {
            return;
        }
        if ($action === 'WRITE' && !$cfg['writes']) {
            return;
        }
        if (!$this->watchAccessOverlaps($address, $width)) {
            return;
        }
        if (($cfg['width'] ?? null) !== null && $width !== $cfg['width']) {
            return;
        }

        if (self::$watchAccessHits >= $cfg['limit']) {
            if (!self::$watchAccessSuppressed) {
                self::$watchAccessSuppressed = true;
                $this->runtime->option()->logger()->warning(sprintf(
                    'WATCH: suppressing further accesses (limit=%d) range=0x%X..0x%X',
                    $cfg['limit'],
                    $cfg['start'],
                    $cfg['end'],
                ));
            }
            return;
        }
        self::$watchAccessHits++;

        $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
        $pm = $this->runtime->context()->cpu()->isProtectedMode() ? 1 : 0;
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
            'WATCH: %s %s %d-bit addr=0x%08X value=%s CS=0x%04X DS=0x%04X ES=0x%04X SS=0x%04X SP=0x%04X EAX=0x%08X EBX=0x%08X ECX=0x%08X EDX=0x%08X ESI=0x%08X EDI=0x%08X linearIP=0x%08X PM=%d lastIP=0x%08X lastIns=%s lastOp=%s',
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
            $lastIp,
            $lastInstructionName,
            $lastOpcodeStr,
        ));

        if (self::stopOnWatchHitEnabled()) {
            $this->maybeDumpCallsiteOnWatchHit();
            throw new HaltException('Stopped by PHPME_STOP_ON_WATCH_HIT');
        }
    }

    private function maybeLogMsDosBootWrite(string $kind, int $address, int $width, int $value): void
    {
        if (!self::shouldWatchMsDosBoot()) {
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

    /**
     * Initialize the FFI interface.
     */
    private static function initFFI(): void
    {
        if (self::$ffi !== null) {
            return;
        }

        // Use the same FFI instance as RustMemoryStream
        self::$ffi = RustMemoryStream::getFFI();

        // Add MemoryAccessor function definitions
        $basePath = dirname(__DIR__, 2);
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $libPath = $basePath . '/rust/target/release/libphp_machine_emulator_native.dylib';
        } elseif ($os === 'Windows') {
            $libPath = $basePath . '/rust/target/release/php_machine_emulator_native.dll';
        } else {
            $libPath = $basePath . '/rust/target/release/libphp_machine_emulator_native.so';
        }

        self::$ffi = FFI::cdef(
            self::getHeaderDefinitions(),
            $libPath
        );
    }

    /**
     * Get FFI header definitions.
     */
    private static function getHeaderDefinitions(): string
    {
        return <<<'C'
// MemoryStream functions (needed for handle)
void* memory_stream_new(size_t size, size_t physical_max_memory_size, size_t swap_size);
void memory_stream_free(void* stream);
size_t memory_stream_logical_max_memory_size(const void* stream);
size_t memory_stream_physical_max_memory_size(const void* stream);
size_t memory_stream_swap_size(const void* stream);
size_t memory_stream_size(const void* stream);
bool memory_stream_ensure_capacity(void* stream, size_t required_offset);
size_t memory_stream_offset(const void* stream);
bool memory_stream_set_offset(void* stream, size_t new_offset);
bool memory_stream_is_eof(const void* stream);
uint8_t memory_stream_byte(void* stream);
int8_t memory_stream_signed_byte(void* stream);
uint16_t memory_stream_short(void* stream);
uint32_t memory_stream_dword(void* stream);
uint64_t memory_stream_qword(void* stream);
size_t memory_stream_read(void* stream, uint8_t* buffer, size_t length);
void memory_stream_write(void* stream, const uint8_t* buffer, size_t length);
void memory_stream_write_byte(void* stream, uint8_t value);
void memory_stream_write_short(void* stream, uint16_t value);
void memory_stream_write_dword(void* stream, uint32_t value);
void memory_stream_write_qword(void* stream, uint64_t value);
uint8_t memory_stream_read_byte_at(const void* stream, size_t address);
void memory_stream_write_byte_at(void* stream, size_t address, uint8_t value);
uint16_t memory_stream_read_short_at(const void* stream, size_t address);
void memory_stream_write_short_at(void* stream, size_t address, uint16_t value);
uint32_t memory_stream_read_dword_at(const void* stream, size_t address);
void memory_stream_write_dword_at(void* stream, size_t address, uint32_t value);
uint64_t memory_stream_read_qword_at(const void* stream, size_t address);
void memory_stream_write_qword_at(void* stream, size_t address, uint64_t value);
void memory_stream_copy_internal(void* stream, size_t src_offset, size_t dest_offset, size_t size);
void memory_stream_copy_from_external(void* stream, const uint8_t* src, size_t src_len, size_t dest_offset);

// MemoryAccessor functions
void* memory_accessor_new(void* memory);
void memory_accessor_free(void* accessor);
bool memory_accessor_allocate(void* accessor, size_t address, size_t size, bool safe);
int64_t memory_accessor_fetch(const void* accessor, size_t address);
int64_t memory_accessor_fetch_by_size(const void* accessor, size_t address, uint32_t size);
int64_t memory_accessor_try_to_fetch(const void* accessor, size_t address);
void memory_accessor_write_16bit(void* accessor, size_t address, int64_t value);
void memory_accessor_write_by_size(void* accessor, size_t address, int64_t value, uint32_t size);
void memory_accessor_write_to_high_bit(void* accessor, size_t address, int64_t value);
void memory_accessor_write_to_low_bit(void* accessor, size_t address, int64_t value);
void memory_accessor_update_flags(void* accessor, int64_t value, uint32_t size);
void memory_accessor_increment(void* accessor, size_t address);
void memory_accessor_decrement(void* accessor, size_t address);
void memory_accessor_add(void* accessor, size_t address, int64_t value);
void memory_accessor_sub(void* accessor, size_t address, int64_t value);

// Flag getters
bool memory_accessor_zero_flag(const void* accessor);
bool memory_accessor_sign_flag(const void* accessor);
bool memory_accessor_overflow_flag(const void* accessor);
bool memory_accessor_carry_flag(const void* accessor);
bool memory_accessor_parity_flag(const void* accessor);
bool memory_accessor_auxiliary_carry_flag(const void* accessor);
bool memory_accessor_direction_flag(const void* accessor);
bool memory_accessor_interrupt_flag(const void* accessor);
bool memory_accessor_instruction_fetch(const void* accessor);

// Flag setters
void memory_accessor_set_zero_flag(void* accessor, bool value);
void memory_accessor_set_sign_flag(void* accessor, bool value);
void memory_accessor_set_overflow_flag(void* accessor, bool value);
void memory_accessor_set_carry_flag(void* accessor, bool value);
void memory_accessor_set_parity_flag(void* accessor, bool value);
void memory_accessor_set_auxiliary_carry_flag(void* accessor, bool value);
void memory_accessor_set_direction_flag(void* accessor, bool value);
void memory_accessor_set_interrupt_flag(void* accessor, bool value);
void memory_accessor_set_instruction_fetch(void* accessor, bool value);

// Control registers
int64_t memory_accessor_read_control_register(const void* accessor, size_t index);
void memory_accessor_write_control_register(void* accessor, size_t index, int64_t value);

// EFER
uint64_t memory_accessor_read_efer(const void* accessor);
void memory_accessor_write_efer(void* accessor, uint64_t value);

// Memory operations
uint8_t memory_accessor_read_from_memory(const void* accessor, size_t address);
void memory_accessor_write_to_memory(void* accessor, size_t address, uint8_t value);
uint8_t memory_accessor_read_raw_byte(const void* accessor, size_t address);
void memory_accessor_write_raw_byte(void* accessor, size_t address, uint8_t value);
uint8_t memory_accessor_read_physical_8(const void* accessor, size_t address);
uint16_t memory_accessor_read_physical_16(const void* accessor, size_t address);
uint32_t memory_accessor_read_physical_32(const void* accessor, size_t address);
void memory_accessor_write_physical_32(void* accessor, size_t address, uint32_t value);
uint64_t memory_accessor_read_physical_64(const void* accessor, size_t address);
void memory_accessor_write_physical_64(void* accessor, size_t address, uint64_t value);

// Linear address translation and memory access with paging
void memory_accessor_translate_linear(void* accessor, uint64_t linear, bool is_write, bool is_user, bool paging_enabled, uint64_t linear_mask, uint64_t* result_physical, uint32_t* result_error);
bool memory_accessor_is_mmio_address(size_t address);
void memory_accessor_read_memory_8(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint8_t* result_value, uint32_t* result_error);
void memory_accessor_read_memory_16(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint16_t* result_value, uint32_t* result_error);
void memory_accessor_read_memory_32(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint32_t* result_value, uint32_t* result_error);
void memory_accessor_read_memory_64(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint64_t* result_value, uint32_t* result_error);
uint32_t memory_accessor_write_memory_8(void* accessor, uint64_t linear, uint8_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
uint32_t memory_accessor_write_memory_16(void* accessor, uint64_t linear, uint16_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
uint32_t memory_accessor_write_memory_32(void* accessor, uint64_t linear, uint32_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
uint32_t memory_accessor_write_memory_64(void* accessor, uint64_t linear, uint64_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
void memory_accessor_write_physical_16(void* accessor, size_t address, uint16_t value);
C;
    }

    public function __construct(
        protected RuntimeInterface $runtime,
        protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection
    ) {
        self::initFFI();

        // Get the memory handle from the runtime
        $memory = $runtime->memory();

        if ($memory instanceof RustMemoryStream) {
            $memoryHandle = $memory->getHandle();
        } else {
            throw new \RuntimeException(
                'RustMemoryAccessor requires RustMemoryStream. ' .
                'Please use RustMemoryStream instead of MemoryStream.'
            );
        }

        $this->handle = self::$ffi->memory_accessor_new($memoryHandle);

        if ($this->handle === null) {
            throw new \RuntimeException('Failed to create Rust MemoryAccessor');
        }

        $this->announceWatchAccessConfigIfRequested();
    }

    private function announceWatchAccessConfigIfRequested(): void
    {
        if (self::$watchAccessAnnounced || self::$watchAccessConfigErrorAnnounced) {
            return;
        }

        $requested = false;
        $addrEnv = getenv('PHPME_WATCH_ADDR');
        if ($addrEnv !== false && trim($addrEnv) !== '') {
            $requested = true;
        }
        $grubEnv = getenv('PHPME_WATCH_GRUB_FREE_MAGIC');
        if ($grubEnv !== false && $grubEnv !== '' && $grubEnv !== '0') {
            $requested = true;
        }
        if (!$requested) {
            $basePath = dirname(__DIR__, 2);
            $requested = is_file($basePath . '/debug/watch_access.txt');
        }

        if (!$requested) {
            return;
        }

        $cfg = self::watchAccessConfig();
        if ($cfg !== null) {
            self::$watchAccessAnnounced = true;
            $this->runtime->option()->logger()->warning(sprintf(
                'WATCH: enabled range=0x%X..0x%X limit=%d reads=%d source=%s',
                $cfg['start'],
                $cfg['end'],
                $cfg['limit'],
                $cfg['reads'] ? 1 : 0,
                $cfg['source'] ?? 'unknown',
            ));
            return;
        }

        if (self::$watchAccessConfigError !== null && !self::$watchAccessConfigErrorAnnounced) {
            self::$watchAccessConfigErrorAnnounced = true;
            $this->runtime->option()->logger()->warning(sprintf('WATCH: disabled (%s)', self::$watchAccessConfigError));
            return;
        }

        if (!self::$watchAccessConfigErrorAnnounced) {
            self::$watchAccessConfigErrorAnnounced = true;
            $this->runtime->option()->logger()->warning('WATCH: disabled (no valid range configured)');
        }
    }

    public function __destruct()
    {
        if (isset($this->handle) && self::$ffi !== null) {
            self::$ffi->memory_accessor_free($this->handle);
        }
    }

    /**
     * Check if address is a register address (skip observer processing).
     */
    private function isRegisterAddress(int $address): bool
    {
        return ($address >= 0 && $address <= 13) || ($address >= 16 && $address <= 25);
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
        self::$ffi->memory_accessor_allocate($this->handle, $address, $size, $safe);
        return $this;
    }

    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);
        $value = self::$ffi->memory_accessor_fetch($this->handle, $address);

        // Determine stored size
        $isGpr = ($address >= 0 && $address <= 7) || ($address >= 16 && $address <= 24);
        $storedSize = $isGpr ? 64 : 16;

        return MemoryAccessorFetchResult::fromCache($value, $storedSize);
    }

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);
        $value = self::$ffi->memory_accessor_try_to_fetch($this->handle, $address);

        if ($value === -1) {
            return null;
        }

        $isGpr = ($address >= 0 && $address <= 7) || ($address >= 16 && $address <= 24);
        $storedSize = $isGpr ? 64 : 16;

        return MemoryAccessorFetchResult::fromCache($value, $storedSize);
    }

    public function increment(int|RegisterType $registerType): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_increment($this->handle, $address);
        return $this;
    }

    public function add(int|RegisterType $registerType, int $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_add($this->handle, $address, $value);
        return $this;
    }

    public function sub(int|RegisterType $registerType, int $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_sub($this->handle, $address, $value);
        return $this;
    }

    public function decrement(int|RegisterType $registerType): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_decrement($this->handle, $address);
        return $this;
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $this->maybeLogWatchedAccess('WRITE', 'bySize', $address, 16, $value ?? 0);
        $this->maybeLogMsDosBootWrite('bySize', $address, 16, $value ?? 0);
        $previousValue = self::$ffi->memory_accessor_fetch($this->handle, $address);
        self::$ffi->memory_accessor_write_16bit($this->handle, $address, $value ?? 0);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        $address = $this->asAddress($registerType);
        $this->maybeLogWatchedAccess('WRITE', 'bySize', $address, $size, $value ?? 0);
        $this->maybeLogMsDosBootWrite('bySize', $address, $size, $value ?? 0);
        $previousValue = self::$ffi->memory_accessor_fetch($this->handle, $address);
        self::$ffi->memory_accessor_write_by_size($this->handle, $address, $value ?? 0, $size);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_write_to_high_bit($this->handle, $address, $value ?? 0);
        return $this;
    }

    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_write_to_low_bit($this->handle, $address, $value ?? 0);
        return $this;
    }

    public function updateFlags(int|null $value, int $size = 16): self
    {
        if ($value === null) {
            self::$ffi->memory_accessor_set_zero_flag($this->handle, true);
            self::$ffi->memory_accessor_set_sign_flag($this->handle, false);
            self::$ffi->memory_accessor_set_overflow_flag($this->handle, false);
            self::$ffi->memory_accessor_set_parity_flag($this->handle, true);
            return $this;
        }

        self::$ffi->memory_accessor_update_flags($this->handle, $value, $size);
        return $this;
    }

    public function setCarryFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_carry_flag($this->handle, $which);
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
                $value |= self::$ffi->memory_accessor_read_from_memory($this->handle, $address + $i) << ($i * 8);
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
        return self::$ffi->memory_accessor_read_control_register($this->handle, $index);
    }

    public function writeControlRegister(int $index, int $value): void
    {
        $previous = self::$ffi->memory_accessor_read_control_register($this->handle, $index);
        self::$ffi->memory_accessor_write_control_register($this->handle, $index, $value);

        // Mode changes can alter instruction decoding/execution semantics
        if ($index === 0 && $previous !== $value) {
            $this->runtime->architectureProvider()->instructionExecutor()->invalidateCaches();
        }
    }

    public function shouldZeroFlag(): bool
    {
        return self::$ffi->memory_accessor_zero_flag($this->handle);
    }

    public function shouldSignFlag(): bool
    {
        return self::$ffi->memory_accessor_sign_flag($this->handle);
    }

    public function shouldOverflowFlag(): bool
    {
        return self::$ffi->memory_accessor_overflow_flag($this->handle);
    }

    public function shouldCarryFlag(): bool
    {
        return self::$ffi->memory_accessor_carry_flag($this->handle);
    }

    public function shouldParityFlag(): bool
    {
        return self::$ffi->memory_accessor_parity_flag($this->handle);
    }

    public function shouldAuxiliaryCarryFlag(): bool
    {
        return self::$ffi->memory_accessor_auxiliary_carry_flag($this->handle);
    }

    public function shouldDirectionFlag(): bool
    {
        return self::$ffi->memory_accessor_direction_flag($this->handle);
    }

    public function shouldInterruptFlag(): bool
    {
        return self::$ffi->memory_accessor_interrupt_flag($this->handle);
    }

    public function setZeroFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_zero_flag($this->handle, $which);
        return $this;
    }

    public function setSignFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_sign_flag($this->handle, $which);
        return $this;
    }

    public function setOverflowFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_overflow_flag($this->handle, $which);
        return $this;
    }

    public function setParityFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_parity_flag($this->handle, $which);
        return $this;
    }

    public function setAuxiliaryCarryFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_auxiliary_carry_flag($this->handle, $which);
        return $this;
    }

    public function setDirectionFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_direction_flag($this->handle, $which);
        return $this;
    }

    public function setInterruptFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_interrupt_flag($this->handle, $which);
        return $this;
    }

    public function writeEfer(int $value): void
    {
        self::$ffi->memory_accessor_write_efer($this->handle, $value);
    }

    /**
     * Read EFER value.
     */
    public function readEfer(): int
    {
        return self::$ffi->memory_accessor_read_efer($this->handle);
    }

    /**
     * Write a raw byte to memory.
     */
    public function writeRawByte(int $address, int $value): self
    {
        $this->maybeLogWatchedAccess('WRITE', 'raw', $address, 8, $value);
        $this->maybeLogMsDosBootWrite('raw', $address, 8, $value);
        $previousValue = self::$ffi->memory_accessor_read_raw_byte($this->handle, $address);
        self::$ffi->memory_accessor_write_raw_byte($this->handle, $address, $value & 0xFF);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    /**
     * Read a raw byte from memory.
     */
    public function readRawByte(int $address): ?int
    {
        $value = self::$ffi->memory_accessor_read_raw_byte($this->handle, $address);
        $this->maybeLogWatchedAccess('READ', 'raw', $address, 8, $value);
        return $value;
    }

    /**
     * Set instruction fetch flag.
     */
    public function setInstructionFetch(bool $flag): self
    {
        self::$ffi->memory_accessor_set_instruction_fetch($this->handle, $flag);
        return $this;
    }

    /**
     * Get instruction fetch flag.
     */
    public function shouldInstructionFetch(): bool
    {
        return self::$ffi->memory_accessor_instruction_fetch($this->handle);
    }

    /**
     * Get the FFI instance.
     */
    public static function getFFI(): FFI
    {
        self::initFFI();
        return self::$ffi;
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
        return self::$ffi->memory_accessor_read_physical_8($this->handle, $address);
    }

    /**
     * Read 16-bit value from physical memory.
     */
    public function readPhysical16(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_16($this->handle, $address);
    }

    /**
     * Read 32-bit value from physical memory.
     */
    public function readPhysical32(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_32($this->handle, $address);
    }

    /**
     * Read 64-bit value from physical memory.
     */
    public function readPhysical64(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_64($this->handle, $address);
    }

    /**
     * Write 32-bit value to physical memory.
     */
    public function writePhysical32(int $address, int $value): void
    {
        $this->maybeLogWatchedAccess('WRITE', 'phys', $address, 32, $value);
        self::$ffi->memory_accessor_write_physical_32($this->handle, $address, $value);
    }

    /**
     * Write 64-bit value to physical memory.
     */
    public function writePhysical64(int $address, int $value): void
    {
        $this->maybeLogWatchedAccess('WRITE', 'phys', $address, 64, $value);
        self::$ffi->memory_accessor_write_physical_64($this->handle, $address, $value);
    }

    /**
     * Translate linear address to physical address through paging.
     * Returns [physical_address, error_code].
     * error_code is 0 on success, 0xFFFFFFFF for MMIO, or (vector << 16) | fault_code for page fault.
     */
    public function translateLinear(int $linear, bool $isWrite, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultPhysical = self::$ffi->new('uint64_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_translate_linear(
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
        $desc64 = self::$ffi->new('uint64_t');
        $descErr = self::$ffi->new('uint32_t');
        self::$ffi->memory_accessor_read_memory_64(
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
            $b0 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset);
            $b1 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 1);
            $b2 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 2);
            $b3 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 3);
            $b4 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 4);
            $b5 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 5);
            $b6 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 6);
            $b7 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 7);
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
    public static function isMmioAddress(int $address): bool
    {
        self::initFFI();
        return self::$ffi->memory_accessor_is_mmio_address($address);
    }

    /**
     * Read 8-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory8(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = self::$ffi->new('uint8_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_8(
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
        $resultValue = self::$ffi->new('uint16_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_16(
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
        $resultValue = self::$ffi->new('uint32_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_32(
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
        $resultValue = self::$ffi->new('uint64_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_64(
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
        return self::$ffi->memory_accessor_write_memory_8(
            $this->handle,
            $linear,
            $value & 0xFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 16-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory16(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 16, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 16, $value);
        return self::$ffi->memory_accessor_write_memory_16(
            $this->handle,
            $linear,
            $value & 0xFFFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 32-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory32(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 32, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 32, $value);
        return self::$ffi->memory_accessor_write_memory_32(
            $this->handle,
            $linear,
            $value & 0xFFFFFFFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 64-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory64(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        $this->maybeLogWatchedAccess('WRITE', 'linear', $linear, 64, $value);
        $this->maybeLogMsDosBootWrite('linear', $linear, 64, $value);
        return self::$ffi->memory_accessor_write_memory_64(
            $this->handle,
            $linear,
            $value,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 16-bit value to physical memory.
     */
    public function writePhysical16(int $address, int $value): void
    {
        $this->maybeLogWatchedAccess('WRITE', 'phys', $address, 16, $value);
        $this->maybeLogMsDosBootWrite('phys', $address, 16, $value);
        self::$ffi->memory_accessor_write_physical_16($this->handle, $address, $value & 0xFFFF);
    }
}
