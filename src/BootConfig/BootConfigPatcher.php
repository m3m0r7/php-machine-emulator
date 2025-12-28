<?php

declare(strict_types=1);

namespace PHPMachineEmulator\BootConfig;

use PHPMachineEmulator\LogicBoard\Debug\BootConfigPatchConfig;

final class BootConfigPatcher
{
    private bool $enabled = true;
    private bool $patchGrubPlatform = true;
    private bool $disableLoadfontUnicode = true;
    private bool $disableDosCdromDrivers = true;
    private ?int $timeoutOverride = 1;

    public function __construct(?BootConfigPatchConfig $config = null)
    {
        $config ??= new BootConfigPatchConfig();
        $this->enabled = $config->enabled;
        $this->patchGrubPlatform = $config->patchGrubPlatform;
        $this->disableLoadfontUnicode = $config->disableLoadfontUnicode;
        $this->disableDosCdromDrivers = $config->disableDosCdromDrivers;
        $this->timeoutOverride = $config->timeoutOverride;
    }

    public function patch(string $data): BootConfigPatchResult
    {
        if (!$this->enabled) {
            return new BootConfigPatchResult($data, []);
        }

        $looksGrub = $this->looksLikeGrubConfig($data);
        $looksDos = $this->looksLikeDosConfig($data);
        if (!$looksGrub && !$looksDos) {
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

        if ($looksGrub && $this->timeoutOverride !== null) {
            $patched = $this->overrideTimeout($patched, $this->timeoutOverride, $applied);
        }

        if ($looksDos && $this->disableDosCdromDrivers) {
            $patched = $this->disableDosCdromDrivers($patched, $applied);
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
    private function disableDosCdromDrivers(string $data, array &$applied): string
    {
        $patched = $data;
        $patched = $this->commentOutDosDriverLine($patched, '\\bCD[0-9]\\.SYS\\b', $applied, 'dos_cd_sys');
        $patched = $this->commentOutDosDriverLine($patched, '\\bMSCDEX\\.EXE\\b', $applied, 'dos_mscdex');
        $patched = $this->commentOutDosDriverLine($patched, '\\bHIMEM\\.SYS\\b', $applied, 'dos_himem');

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

        $himemCount = 0;
        $patched = preg_replace(
            '/\\bHIMEM\\.SYS\\b/i',
            'HIMEM.SY_',
            $patched,
            -1,
            $himemCount,
        );
        if ($himemCount > 0) {
            $applied[] = 'dos_himem';
        }
        return $patched;
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
