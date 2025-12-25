<?php

declare(strict_types=1);

namespace PHPMachineEmulator\BootConfig;

use PHPMachineEmulator\LogicBoard\Debug\BootConfigPatchConfig;

final class BootConfigPatcher
{
    private bool $enabled = true;
    private bool $patchGrubPlatform = true;
    private bool $disableLoadfontUnicode = true;
    private ?int $timeoutOverride = 1;

    public function __construct(?BootConfigPatchConfig $config = null)
    {
        $config ??= new BootConfigPatchConfig();
        $this->enabled = $config->enabled;
        $this->patchGrubPlatform = $config->patchGrubPlatform;
        $this->disableLoadfontUnicode = $config->disableLoadfontUnicode;
        $this->timeoutOverride = $config->timeoutOverride;
    }

    public function patch(string $data): BootConfigPatchResult
    {
        if (!$this->enabled) {
            return new BootConfigPatchResult($data, []);
        }

        if (!$this->looksLikeGrubConfig($data)) {
            return new BootConfigPatchResult($data, []);
        }

        $originalLen = strlen($data);
        $patched = $data;
        $applied = [];

        if ($this->patchGrubPlatform) {
            $patched = $this->commentOutStandaloneDirective(
                $patched,
                'grub_platform',
                '#rub_platform',
                $applied,
                'grub_platform',
            );
        }

        if ($this->disableLoadfontUnicode) {
            $patched = $this->commentOutLoadfontUnicode($patched, $applied);
        }

        if ($this->timeoutOverride !== null) {
            $patched = $this->overrideTimeout($patched, $this->timeoutOverride, $applied);
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
