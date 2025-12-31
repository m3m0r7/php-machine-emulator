<?php

declare(strict_types=1);

namespace PHPMachineEmulator\BootConfig;

use PHPMachineEmulator\LogicBoard\Debug\BootConfigPatchConfig;

final class BootConfigPatcher
{
    private bool $enabled = true;
    private bool $patchGrubPlatform = true;
    private bool $disableLoadfontUnicode = true;
    private bool $forceGrubTextMode = true;
    private bool $disableDosCdromDrivers = true;
    private ?int $timeoutOverride = 1;
    private bool $disableSyslinuxUi = true;
    private ?int $syslinuxTimeoutOverride = null;

    public function __construct(?BootConfigPatchConfig $config = null)
    {
        $config ??= new BootConfigPatchConfig();
        $this->enabled = $config->enabled;
        $this->patchGrubPlatform = $config->patchGrubPlatform;
        $this->disableLoadfontUnicode = $config->disableLoadfontUnicode;
        $this->forceGrubTextMode = $config->forceGrubTextMode;
        $this->disableDosCdromDrivers = $config->disableDosCdromDrivers;
        $this->timeoutOverride = $config->timeoutOverride;
        $this->disableSyslinuxUi = $config->disableSyslinuxUi;
        $this->syslinuxTimeoutOverride = $config->syslinuxTimeoutOverride;
    }

    public function patch(string $data): BootConfigPatchResult
    {
        if (!$this->enabled) {
            return new BootConfigPatchResult($data, []);
        }

        $looksGrub = $this->looksLikeGrubConfig($data);
        $looksDos = $this->looksLikeDosConfig($data);
        $looksSyslinux = $this->looksLikeSyslinuxConfig($data);
        if (!$looksGrub && !$looksDos && !$looksSyslinux) {
            return new BootConfigPatchResult($data, []);
        }

        $originalLen = strlen($data);
        $patched = $data;
        $applied = [];

        if ($looksGrub && $this->patchGrubPlatform) {
            $patched = $this->commentOutStandaloneDirective(
                $patched,
                'grub_platform',
                '#rub_platform',
                $applied,
                'grub_platform',
            );
        }

        if ($looksGrub && $this->disableLoadfontUnicode) {
            $patched = $this->commentOutLoadfontUnicode($patched, $applied);
        }

        if ($looksGrub && $this->forceGrubTextMode) {
            $patched = $this->overrideGrubGfxpayload($patched, 'text', $applied);
        }

        if ($looksGrub && $this->timeoutOverride !== null) {
            $patched = $this->overrideTimeout($patched, $this->timeoutOverride, $applied);
        }

        if ($looksDos && $this->disableDosCdromDrivers) {
            $patched = $this->disableDosCdromDrivers($patched, $applied);
            $patched = $this->overrideLastDrive($patched, 'A', $applied);
        }

        if ($looksSyslinux && $this->disableSyslinuxUi) {
            $patched = $this->commentOutSyslinuxUi($patched, $applied);
        }

        if ($looksSyslinux && $this->syslinuxTimeoutOverride !== null) {
            $patched = $this->overrideSyslinuxTimeout($patched, $this->syslinuxTimeoutOverride, $applied);
        }

        if ($patched !== $data) {
            $patched = $this->normalizeLength($patched, $originalLen);
        }

        return new BootConfigPatchResult($patched, $applied);
    }

    private function looksLikeGrubConfig(string $data): bool
    {
        if (!str_contains($data, 'menuentry')) {
            return false;
        }
        return str_contains($data, 'set timeout=');
    }

    private function looksLikeDosConfig(string $data): bool
    {
        return stripos($data, 'DEVICE=') !== false || stripos($data, 'MSCDEX') !== false;
    }

    private function looksLikeSyslinuxConfig(string $data): bool
    {
        if (!preg_match('/^\\s*LABEL\\s+\\S+/mi', $data)) {
            return false;
        }
        if (!preg_match('/^\\s*(KERNEL|LINUX|APPEND)\\s+/mi', $data)) {
            return false;
        }
        return preg_match('/^\\s*(DEFAULT|UI|TIMEOUT)\\s+/mi', $data) === 1;
    }

    /**
     * @param array<int,string> $applied
     */
    private function commentOutStandaloneDirective(
        string $data,
        string $directive,
        string $replacement,
        array &$applied,
        string $ruleName,
    ): string {
        if (!str_contains($data, $directive)) {
            return $data;
        }

        $pattern = sprintf('/^(\\s*)%s(\\s*)$/m', preg_quote($directive, '/'));
        $patched = preg_replace_callback(
            $pattern,
            static fn(array $m): string => ($m[1] ?? '') . $replacement . ($m[2] ?? ''),
            $data,
        );

        if (is_string($patched) && $patched !== $data) {
            $applied[] = $ruleName;
            return $patched;
        }

        return $data;
    }

    /**
     * @param array<int,string> $applied
     */
    private function commentOutLoadfontUnicode(string $data, array &$applied): string
    {
        if (!str_contains($data, 'loadfont')) {
            return $data;
        }

        $patched = preg_replace_callback(
            '/^(\\s*)loadfont(\\s+unicode\\s*)$/mi',
            static fn(array $m): string => ($m[1] ?? '') . '#oadfont' . ($m[2] ?? ''),
            $data,
        );

        if (is_string($patched) && $patched !== $data) {
            $applied[] = 'loadfont_unicode';
            return $patched;
        }

        return $data;
    }

    /**
     * @param array<int,string> $applied
     */
    private function overrideGrubGfxpayload(string $data, string $payload, array &$applied): string
    {
        if (!str_contains($data, 'gfxpayload')) {
            return $data;
        }

        $patched = preg_replace_callback(
            '/^(\\s*set\\s+gfxpayload=)([^\\r\\n]*)/mi',
            static function (array $m) use ($payload): string {
                $value = $m[2] ?? '';
                $len = max(1, strlen($value));
                $normalized = str_pad($payload, $len, ' ', STR_PAD_RIGHT);
                if (strlen($normalized) > $len) {
                    $normalized = substr($normalized, 0, $len);
                }
                return ($m[1] ?? '') . $normalized;
            },
            $data,
            1,
            $count,
        );

        if (is_string($patched) && $patched !== $data && $count > 0) {
            $applied[] = 'gfxpayload';
            return $patched;
        }

        return $data;
    }

    /**
     * @param array<int,string> $applied
     */
    private function overrideTimeout(string $data, int $timeout, array &$applied): string
    {
        if (!str_contains($data, 'set timeout=')) {
            return $data;
        }

        $patched = preg_replace_callback(
            '/(set\\s+timeout=)(\\d+)/i',
            static function (array $m) use ($timeout): string {
                $digits = (string) ($m[2] ?? '0');
                $len = max(1, strlen($digits));
                $normalized = str_pad((string) $timeout, $len, '0', STR_PAD_LEFT);
                if (strlen($normalized) > $len) {
                    $normalized = substr($normalized, -$len);
                }
                return ($m[1] ?? '') . $normalized;
            },
            $data,
            1,
        );

        if (is_string($patched) && $patched !== $data) {
            $applied[] = 'timeout';
            return $patched;
        }

        return $data;
    }

    /**
     * @param array<int,string> $applied
     */
    private function overrideSyslinuxTimeout(string $data, int $timeout, array &$applied): string
    {
        if (!str_contains($data, 'TIMEOUT')) {
            return $data;
        }

        $patched = preg_replace_callback(
            '/^(\\s*TIMEOUT\\s+)(\\d+)/mi',
            static function (array $m) use ($timeout): string {
                $digits = (string) ($m[2] ?? '0');
                $len = max(1, strlen($digits));
                $normalized = str_pad((string) $timeout, $len, '0', STR_PAD_LEFT);
                if (strlen($normalized) > $len) {
                    $normalized = substr($normalized, -$len);
                }
                return ($m[1] ?? '') . $normalized;
            },
            $data,
            1,
            $count,
        );

        if (is_string($patched) && $patched !== $data && $count > 0) {
            $applied[] = 'syslinux_timeout';
            return $patched;
        }

        return $data;
    }

    /**
     * @param array<int,string> $applied
     */
    private function disableDosCdromDrivers(string $data, array &$applied): string
    {
        $patched = $data;
        $patched = $this->commentOutDosDriverLine($patched, '\\bCD[0-9]\\.SYS\\b', $applied, 'dos_cd_sys');
        $patched = $this->commentOutDosDriverLine($patched, '\\bMSCDEX\\.EXE\\b', $applied, 'dos_mscdex');

        $cdCount = 0;
        $patched = preg_replace_callback(
            '/\\bCD([0-9])\\.SYS\\b/i',
            static fn(array $m): string => 'CD' . ($m[1] ?? '') . '.SY_',
            $patched,
            -1,
            $cdCount,
        );
        if ($cdCount > 0) {
            $applied[] = 'dos_cd_sys';
        }

        $mscCount = 0;
        $patched = preg_replace(
            '/\\bMSCDEX\\.EXE\\b/i',
            'MSCDEX.EX_',
            $patched,
            -1,
            $mscCount,
        );
        if ($mscCount > 0) {
            $applied[] = 'dos_mscdex';
        }

        return $patched;
    }

    /**
     * @param array<int,string> $applied
     */
    private function commentOutSyslinuxUi(string $data, array &$applied): string
    {
        if (!str_contains($data, 'UI')) {
            return $data;
        }

        $count = 0;
        $patched = preg_replace_callback(
            '/^\\s*UI\\s+[^\\r\\n]*/mi',
            static function (array $m): string {
                $line = $m[0] ?? '';
                if ($line === '') {
                    return $line;
                }
                return '#' . substr($line, 1);
            },
            $data,
            -1,
            $count,
        );

        if (is_string($patched) && $count > 0) {
            $applied[] = 'syslinux_ui';
            return $patched;
        }

        return $data;
    }

    /**
     * @param array<int,string> $applied
     */
    private function overrideLastDrive(string $data, string $drive, array &$applied): string
    {
        $drive = strtoupper(substr($drive, 0, 1));
        if ($drive === '') {
            return $data;
        }

        $count = 0;
        $patched = preg_replace_callback(
            '/^(\\s*LASTDRIVE\\s*=\\s*)([A-Z])\\b/mi',
            static fn(array $m): string => ($m[1] ?? '') . $drive,
            $data,
            1,
            $count,
        );

        if (is_string($patched) && $count > 0 && $patched !== $data) {
            $applied[] = 'lastdrive';
            return $patched;
        }

        return $data;
    }

    /**
     * Comment out any line containing the given DOS driver pattern.
     *
     * @param array<int,string> $applied
     */
    private function commentOutDosDriverLine(
        string $data,
        string $pattern,
        array &$applied,
        string $ruleName
    ): string {
        $count = 0;
        $regex = '/^[^\\r\\n]*' . $pattern . '[^\\r\\n]*/mi';
        $patched = preg_replace_callback(
            $regex,
            static function (array $m): string {
                $line = $m[0];
                $len = strlen($line);
                if ($len >= 4) {
                    return 'REM ' . substr($line, 4);
                }
                return str_pad('REM ', $len, ' ');
            },
            $data,
            -1,
            $count,
        );

        if ($count > 0 && is_string($patched)) {
            $applied[] = $ruleName;
            return $patched;
        }

        return $data;
    }

    private function normalizeLength(string $data, int $originalLen): string
    {
        $current = strlen($data);
        if ($current === $originalLen) {
            return $data;
        }

        if ($current > $originalLen) {
            return substr($data, 0, $originalLen);
        }

        return $data . str_repeat("\x00", $originalLen - $current);
    }
}
