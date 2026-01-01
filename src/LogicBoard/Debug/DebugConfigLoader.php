<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class DebugConfigLoader
{
    private string $configDir;
    private string $projectRoot;
    private DebugConfigParser $parser;

    public function __construct(?string $configDir = null, ?string $projectRoot = null, ?DebugConfigParser $parser = null)
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 3);
        $this->configDir = $configDir ?? ($this->projectRoot . '/config/debug');
        $this->parser = $parser ?? new DebugConfigParser();
    }

    public function load(): DebugContext
    {
        $execution = $this->loadFile('execution.php');
        $screen = $this->loadFile('screen.php');
        $memoryAccess = $this->loadFile('memory_access.php');
        $patterns = $this->loadFile('patterns.php');
        $trace = $this->loadFile('trace.php');
        $watch = $this->loadFile('watch.php');
        $boot = $this->loadFile('boot.php');

        $executionConfig = $this->buildExecutionConfig($execution);

        return new DebugContext(
            countInstructionsEnabled: $executionConfig['countInstructionsEnabled'],
            ipSampleEvery: $executionConfig['ipSampleEvery'],
            stopAfterInsns: $executionConfig['stopAfterInsns'],
            stopAfterSecs: $executionConfig['stopAfterSecs'],
            stopAfterTimeEvery: $executionConfig['stopAfterTimeEvery'],
            traceExecution: $executionConfig['traceExecution'],
            traceIpSet: $executionConfig['traceIpSet'],
            traceIpLimit: $executionConfig['traceIpLimit'],
            stopIpSet: $executionConfig['stopIpSet'],
            traceCflowToSet: $executionConfig['traceCflowToSet'],
            traceCflowLimit: $executionConfig['traceCflowLimit'],
            stopCflowToSet: $executionConfig['stopCflowToSet'],
            stopOnRspBelowThreshold: $executionConfig['stopOnRspBelowThreshold'],
            stopOnCflowToBelowThreshold: $executionConfig['stopOnCflowToBelowThreshold'],
            stopOnIpDropBelowThreshold: $executionConfig['stopOnIpDropBelowThreshold'],
            zeroOpcodeLoopLimit: $executionConfig['zeroOpcodeLoopLimit'],
            stackPreviewOnIpStopBytes: $executionConfig['stackPreviewOnIpStopBytes'],
            dumpCodeOnIpStopLength: $executionConfig['dumpCodeOnIpStopLength'],
            dumpCodeOnIpStopBefore: $executionConfig['dumpCodeOnIpStopBefore'],
            dumpPageFaultContext: $executionConfig['dumpPageFaultContext'],
            dumpCodeOnPfLength: $executionConfig['dumpCodeOnPfLength'],
            dumpCodeOnPfBefore: $executionConfig['dumpCodeOnPfBefore'],
            pfComparePhysDelta: $executionConfig['pfComparePhysDelta'],
            stopOnIa32eActive: $executionConfig['stopOnIa32eActive'],
            screenConfig: $this->buildScreenConfig($screen),
            memoryAccessConfig: $this->buildMemoryAccessConfig($memoryAccess),
            patternConfig: $this->buildPatternConfig($patterns),
            traceConfig: $this->buildTraceConfig($trace),
            watchConfig: $this->buildWatchConfig($watch),
            bootConfig: $this->buildBootConfig($boot),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function loadFile(string $name): array
    {
        $path = $this->configDir . '/' . $name;
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildExecutionConfig(array $data): array
    {
        $traceExecution = null;
        if (array_key_exists('trace_execution', $data)) {
            $traceExecution = $data['trace_execution'] === null
                ? null
                : $this->parser->parseBool($data['trace_execution']);
        }

        return [
            'countInstructionsEnabled' => $this->parser->parseBool($data['count_instructions_enabled'] ?? false),
            'ipSampleEvery' => $this->parser->parseInt($data['ip_sample_every'] ?? null) ?? 0,
            'stopAfterInsns' => $this->parser->parseInt($data['stop_after_insns'] ?? null) ?? 0,
            'stopAfterSecs' => $this->parser->parseInt($data['stop_after_secs'] ?? null) ?? 0,
            'stopAfterTimeEvery' => $this->parser->parseInt($data['stop_after_time_every'] ?? null) ?? 10000,
            'traceExecution' => $traceExecution,
            'traceIpSet' => $this->parser->parseIntSet($data['trace_ip'] ?? []),
            'traceIpLimit' => $this->parser->parseInt($data['trace_ip_limit'] ?? null) ?? 10,
            'stopIpSet' => $this->parser->parseIntSet($data['stop_ip'] ?? []),
            'traceCflowToSet' => $this->parser->parseIntSet($data['trace_cflow_to'] ?? []),
            'traceCflowLimit' => $this->parser->parseInt($data['trace_cflow_limit'] ?? null) ?? 10,
            'stopCflowToSet' => $this->parser->parseIntSet($data['stop_cflow_to'] ?? []),
            'stopOnRspBelowThreshold' => $this->parser->parseInt($data['stop_on_rsp_below_threshold'] ?? null) ?? 0,
            'stopOnCflowToBelowThreshold' => $this->parser->parseInt($data['stop_on_cflow_to_below_threshold'] ?? null) ?? 0,
            'stopOnIpDropBelowThreshold' => $this->parser->parseInt($data['stop_on_ip_drop_below_threshold'] ?? null) ?? 0,
            'zeroOpcodeLoopLimit' => $this->parser->parseInt($data['zero_opcode_loop_limit'] ?? null) ?? 0,
            'stackPreviewOnIpStopBytes' => $this->parser->parseInt($data['stack_preview_on_ip_stop_bytes'] ?? null) ?? 0,
            'dumpCodeOnIpStopLength' => $this->parser->parseInt($data['dump_code_on_ip_stop_length'] ?? null) ?? 0,
            'dumpCodeOnIpStopBefore' => $this->parser->parseInt($data['dump_code_on_ip_stop_before'] ?? null) ?? 0,
            'dumpPageFaultContext' => $this->parser->parseBool($data['dump_page_fault_context'] ?? false),
            'dumpCodeOnPfLength' => $this->parser->parseInt($data['dump_code_on_pf_length'] ?? null) ?? 0,
            'dumpCodeOnPfBefore' => $this->parser->parseInt($data['dump_code_on_pf_before'] ?? null) ?? 0,
            'pfComparePhysDelta' => $this->parser->parseInt($data['pf_compare_phys_delta'] ?? null) ?? 0,
            'stopOnIa32eActive' => $this->parser->parseBool($data['stop_on_ia32e_active'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildScreenConfig(array $data): ScreenDebugConfig
    {
        $stopOnSubstr = null;
        if (isset($data['stop_on_screen_substr']) && is_string($data['stop_on_screen_substr'])) {
            $stopOnSubstr = trim($data['stop_on_screen_substr']);
            if ($stopOnSubstr === '') {
                $stopOnSubstr = null;
            }
        }
        if ($stopOnSubstr === null && $this->parser->parseBool($data['stop_on_grub_free_magic'] ?? false)) {
            $stopOnSubstr = 'free magic is broken';
        }

        $tail = $this->parser->parseInt($data['stop_on_screen_tail'] ?? null) ?? 0;
        $tail = $this->clamp($tail, 0, 4096);

        $dumpMem = null;
        if (isset($data['dump_mem']) && is_array($data['dump_mem'])) {
            $addr = $this->parser->parseInt($data['dump_mem']['address'] ?? null)
                ?? $this->parser->parseInt($data['dump_mem']['addr'] ?? null);
            $len = $this->parser->parseInt($data['dump_mem']['length'] ?? null)
                ?? $this->parser->parseInt($data['dump_mem']['len'] ?? null);
            $save = $this->parser->parseBool($data['dump_mem']['save'] ?? false);
            if ($addr !== null && $len !== null && $len > 0) {
                $dumpMem = new ScreenDumpMemoryConfig(
                    $addr & 0xFFFFFFFF,
                    $this->clamp($len, 1, 4096),
                    $save
                );
            }
        }

        $dumpCode = null;
        if (isset($data['dump_code']) && is_array($data['dump_code'])) {
            $len = $this->parser->parseInt($data['dump_code']['length'] ?? null)
                ?? $this->parser->parseInt($data['dump_code']['len'] ?? null);
            if ($len !== null && $len > 0) {
                $before = $this->parser->parseInt($data['dump_code']['before'] ?? null) ?? 32;
                $dumpCode = new ScreenDumpCodeConfig(
                    $this->clamp($len, 1, 4096),
                    $this->clamp($before, 0, 4096),
                );
            }
        }

        $stackLen = $this->parser->parseInt($data['dump_stack'] ?? null);
        if ($stackLen !== null && $stackLen > 0) {
            $stackLen = $this->clamp($stackLen, 1, 4096);
        } else {
            $stackLen = null;
        }

        return new ScreenDebugConfig(
            stopOnScreenSubstr: $stopOnSubstr,
            stopOnScreenTail: $tail,
            dumpMemory: $dumpMem,
            dumpScreenAll: $this->parser->parseBool($data['dump_screen_all'] ?? false),
            dumpCode: $dumpCode,
            dumpStackLength: $stackLen,
            dumpPointerStrings: $this->parser->parseBool($data['dump_ptr_strings'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildMemoryAccessConfig(array $data): MemoryAccessDebugConfig
    {
        return new MemoryAccessDebugConfig(
            renderLfbToTerminal: $this->parser->parseBool($data['render_lfb_terminal'] ?? false),
            stopOnLfbWrite: $this->parser->parseBool($data['stop_on_lfb_write'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildPatternConfig(array $data): PatternDebugConfig
    {
        return new PatternDebugConfig(
            traceHotPatterns: $this->parser->parseBool($data['trace_hot_patterns'] ?? false),
            enableLzmaPattern: $this->parser->parseBool($data['enable_lzma_pattern'] ?? true),
            enableLzmaLoopOptimization: $this->parser->parseBool($data['enable_lzma_loop_optimization'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildTraceConfig(array $data): TraceDebugConfig
    {
        $traceInt10CallsLimit = $this->parser->parseInt($data['trace_int10_calls_limit'] ?? null) ?? 0;
        $traceInt13ReadsLimit = $this->parser->parseInt($data['trace_int13_reads_limit'] ?? null) ?? 0;

        return new TraceDebugConfig(
            traceGrubCfgCopy: $this->parser->parseBool($data['trace_grub_cfg_copy'] ?? false),
            traceInt10CallsLimit: max(0, $traceInt10CallsLimit),
            traceInt13ReadsLimit: max(0, $traceInt13ReadsLimit),
            traceInt13Caller: $this->parser->parseBool($data['trace_int13_caller'] ?? false),
            traceInt15_87: $this->parser->parseBool($data['trace_int15_87'] ?? false),
            stopOnInt13ReadLbaSet: $this->parser->parseIntSet($data['stop_on_int13_read_lba'] ?? []),
            stopOnInt10WriteString: $this->parser->parseBool($data['stop_on_int10_write_string'] ?? false),
            stopOnSetVideoMode: $this->parser->parseBool($data['stop_on_set_video_mode'] ?? false),
            stopOnVbeSetMode: $this->parser->parseBool($data['stop_on_vbe_setmode'] ?? false),
            stopOnInt16Wait: $this->parser->parseBool($data['stop_on_int16_wait'] ?? false),
            traceInterruptFlag: $this->parser->parseBool($data['trace_interrupt_flag'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildWatchConfig(array $data): WatchDebugConfig
    {
        $access = null;
        if (isset($data['access']) && is_array($data['access'])) {
            $access = $this->buildWatchAccessConfig($data['access'], 'config');
        }
        if ($access === null) {
            $fileCfg = $this->loadWatchAccessFile();
            if ($fileCfg !== null) {
                $access = $this->buildWatchAccessConfig($fileCfg, 'file');
            }
        }

        $dumpCallsiteBytes = $this->parser->parseInt($data['dump_callsite_bytes'] ?? null) ?? 512;
        $dumpCallsiteBytes = $this->clamp($dumpCallsiteBytes, 64, 4096);

        return new WatchDebugConfig(
            access: $access,
            stopOnWatchHit: $this->parser->parseBool($data['stop_on_watch_hit'] ?? false),
            dumpCallsiteOnWatchHit: $this->parser->parseBool($data['dump_callsite_on_watch_hit'] ?? false),
            dumpIpOnWatchHit: $this->parser->parseBool($data['dump_ip_on_watch_hit'] ?? false),
            dumpCallsiteBytes: $dumpCallsiteBytes,
            watchMsDosBoot: $this->parser->parseBool($data['watch_msdos_boot'] ?? false),
            stopOnRspZero: $this->parser->parseBool($data['stop_on_rsp_zero'] ?? false),
            stopOnRspBelowThreshold: $this->parser->parseInt($data['stop_on_rsp_below'] ?? null) ?? 0,
            stopOnStackUnderflow: $this->parser->parseBool($data['stop_on_stack_underflow'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildBootConfig(array $data): BootConfigPatchConfig
    {
        $timeoutOverride = 1;
        if (array_key_exists('timeout_override', $data)) {
            $value = $data['timeout_override'];
            if ($value === null) {
                $timeoutOverride = null;
            } elseif (is_string($value)) {
                $trimmed = strtolower(trim($value));
                if (in_array($trimmed, ['off', 'disable'], true)) {
                    $timeoutOverride = null;
                } else {
                    $timeoutOverride = $this->parser->parseInt($trimmed);
                }
            } else {
                $timeoutOverride = $this->parser->parseInt($value);
            }
        }

        $syslinuxTimeoutOverride = null;
        if (array_key_exists('syslinux_timeout_override', $data)) {
            $value = $data['syslinux_timeout_override'];
            if ($value === null) {
                $syslinuxTimeoutOverride = null;
            } elseif (is_string($value)) {
                $trimmed = strtolower(trim($value));
                if (in_array($trimmed, ['off', 'disable'], true)) {
                    $syslinuxTimeoutOverride = null;
                } else {
                    $syslinuxTimeoutOverride = $this->parser->parseInt($trimmed);
                }
            } else {
                $syslinuxTimeoutOverride = $this->parser->parseInt($value);
            }
        }

        return new BootConfigPatchConfig(
            enabled: $this->parser->parseBool($data['enabled'] ?? false, true),
            patchGrubPlatform: $this->parser->parseBool($data['patch_grub_platform'] ?? false, true),
            disableLoadfontUnicode: $this->parser->parseBool($data['disable_loadfont_unicode'] ?? false, true),
            forceGrubTextMode: $this->parser->parseBool($data['force_grub_text_mode'] ?? false, true),
            disableDosCdromDrivers: $this->parser->parseBool($data['disable_dos_cdrom_drivers'] ?? false, true),
            timeoutOverride: $timeoutOverride,
            disableSyslinuxUi: $this->parser->parseBool($data['disable_syslinux_ui'] ?? false, true),
            syslinuxTimeoutOverride: $syslinuxTimeoutOverride,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildWatchAccessConfig(array $data, string $source): ?WatchAccessConfig
    {
        $grub = $this->parser->parseBool($data['grub_free_magic'] ?? $data['grub'] ?? false);
        if ($grub) {
            return new WatchAccessConfig(
                start: 0x7FFFE820,
                end: 0x7FFFE83F,
                limit: $this->clamp($this->parser->parseInt($data['limit'] ?? null) ?? 64, 1, 1000000),
                reads: $this->parser->parseBool($data['reads'] ?? false),
                writes: $this->parser->parseBool($data['writes'] ?? true, true),
                width: $this->normalizeWidth($this->parser->parseInt($data['width'] ?? null)),
                excludeIpRanges: $this->parser->parseRangeList($data['exclude_ip'] ?? []),
                armAfterInt13Lba: $this->parser->parseInt($data['arm_after_int13_lba'] ?? null),
                source: $source . '(grub)',
            );
        }

        $addr = $data['addr'] ?? $data['address'] ?? $data['range'] ?? null;
        $len = $this->parser->parseInt($data['len'] ?? $data['length'] ?? null) ?? 1;
        $parsed = $this->parser->parseRangeExpr($addr, $len);
        if ($parsed === null) {
            return null;
        }

        $limit = $this->parser->parseInt($data['limit'] ?? null) ?? 64;
        $limit = $this->clamp($limit, 1, 1000000);

        $reads = $this->parser->parseBool($data['reads'] ?? false);
        $writes = $this->parser->parseBool($data['writes'] ?? true, true);
        $width = $this->normalizeWidth($this->parser->parseInt($data['width'] ?? null));
        $exclude = $this->parser->parseRangeList($data['exclude_ip'] ?? []);
        $arm = $this->parser->parseInt($data['arm_after_int13_lba'] ?? null);

        return new WatchAccessConfig(
            start: $parsed['start'] & 0xFFFFFFFF,
            end: $parsed['end'] & 0xFFFFFFFF,
            limit: $limit,
            reads: $reads,
            writes: $writes,
            width: $width,
            excludeIpRanges: $exclude,
            armAfterInt13Lba: $arm,
            source: $source,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadWatchAccessFile(): ?array
    {
        $path = $this->projectRoot . '/debug/watch_access.txt';
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
                        $parsed = $this->parser->parseInt($value);
                        if ($parsed !== null) {
                            $len = max(1, $parsed);
                        }
                        break;
                    case 'limit':
                        $parsed = $this->parser->parseInt($value);
                        if ($parsed !== null) {
                            $limit = max(1, $parsed);
                        }
                        break;
                    case 'reads':
                    case 'read':
                        $reads = $this->parser->parseBool($value);
                        break;
                    case 'writes':
                    case 'write':
                        $writes = $this->parser->parseBool($value);
                        break;
                    case 'width':
                        $parsed = $this->parser->parseInt($value);
                        if ($parsed !== null) {
                            $width = $parsed;
                        }
                        break;
                    case 'grub_free_magic':
                    case 'grub':
                        $grub = $this->parser->parseBool($value);
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

    private function normalizeWidth(?int $width): ?int
    {
        if (!in_array($width, [8, 16, 32, 64], true)) {
            return null;
        }
        return $width;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
