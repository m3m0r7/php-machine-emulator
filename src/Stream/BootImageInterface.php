<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface BootImageInterface
{
    public function data(): string;

    public function size(): int;

    public function loadSegment(): int;

    public function loadAddress(): int;

    public function mediaType(): int;

    public function isNoEmulation(): bool;

    public function loadRBA(): int;

    public function catalogSectorCount(): int;

    public function readAt(int $offset, int $length): string;

    public function replaceRange(int $offset, string $data): void;

    /**
     * @return array<string, array{cluster: int, size: int, offset: int}>
     */
    public function getFileIndex(): array;

    /**
     * @return array{cluster: int, size: int, offset: int}|null
     */
    public function getFileInfo(string $filename): ?array;
}
