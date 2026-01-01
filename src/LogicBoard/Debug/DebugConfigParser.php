<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Debug;

final class DebugConfigParser
{
    public function parseBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));
            if ($trimmed === '') {
                return $default;
            }
            return !in_array($trimmed, ['0', 'false', 'no', 'off'], true);
        }
        return $default;
    }

    public function parseInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '0x') || str_starts_with($trimmed, '0X')) {
            $hex = substr($trimmed, 2);
            if ($hex === '' || preg_match('/[^0-9a-fA-F]/', $hex) === 1) {
                return null;
            }
            return (int) hexdec($hex);
        }
        if (preg_match('/^-?\\d+$/', $trimmed) !== 1) {
            return null;
        }
        return (int) $trimmed;
    }

    /**
     * @return array{start:int,end:int}|null
     */
    public function parseRangeExpr(mixed $value, int $defaultLen = 1): ?array
    {
        if (is_int($value)) {
            $len = max(1, $defaultLen);
            return [
                'start' => $value,
                'end' => $value + ($len - 1),
            ];
        }
        if (!is_string($value)) {
            return null;
        }
        $expr = trim($value);
        if ($expr === '') {
            return null;
        }
        $sep = str_contains($expr, '-') ? '-' : (str_contains($expr, ':') ? ':' : null);
        if ($sep !== null) {
            [$a, $b] = array_map('trim', explode($sep, $expr, 2));
            $aVal = $this->parseInt($a);
            $bVal = $this->parseInt($b);
            if ($aVal === null || $bVal === null) {
                return null;
            }
            return [
                'start' => min($aVal, $bVal),
                'end' => max($aVal, $bVal),
            ];
        }

        $aVal = $this->parseInt($expr);
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
    public function parseRangeList(mixed $value): array
    {
        if (is_array($value)) {
            $ranges = [];
            foreach ($value as $item) {
                if (is_array($item) && isset($item['start'], $item['end'])) {
                    $start = $this->parseInt($item['start']);
                    $end = $this->parseInt($item['end']);
                    if ($start !== null && $end !== null) {
                        $ranges[] = ['start' => $start, 'end' => $end];
                    }
                    continue;
                }
                $parsed = $this->parseRangeExpr($item, 1);
                if ($parsed !== null) {
                    $ranges[] = $parsed;
                }
            }
            return $ranges;
        }

        if (!is_string($value)) {
            return [];
        }
        $trimmed = trim($value);
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
            $parsed = $this->parseRangeExpr($p, 1);
            if ($parsed !== null) {
                $ranges[] = $parsed;
            }
        }
        return $ranges;
    }

    /**
     * @return array<int,true>
     */
    public function parseIntSet(mixed $value): array
    {
        if (is_array($value)) {
            $set = [];
            foreach ($value as $item) {
                $parsed = $this->parseInt($item);
                if ($parsed !== null) {
                    $set[$parsed] = true;
                }
            }
            return $set;
        }

        if (!is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0') {
            return [];
        }

        $parts = preg_split('/[\\s,]+/', $trimmed);
        $set = [];
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            $p = trim($part);
            if ($p === '') {
                continue;
            }
            $parsed = $this->parseInt($p);
            if ($parsed !== null) {
                $set[$parsed] = true;
            }
        }
        return $set;
    }
}
