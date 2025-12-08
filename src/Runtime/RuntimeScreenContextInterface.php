<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Display\Writer\ScreenWriterInterface;

interface RuntimeScreenContextInterface
{
    public function screenWriter(): ScreenWriterInterface;

    public function write(string $value): void;

    public function start(): void;

    public function stop(): void;

    public function flushIfNeeded(): void;

    public function setCursorPosition(int $row, int $col): void;

    /**
     * @return array{row: int, col: int}
     */
    public function getCursorPosition(): array;

    public function clear(): void;

    public function setCurrentAttribute(int $attribute): void;

    public function getCurrentAttribute(): int;
}
