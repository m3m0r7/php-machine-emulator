<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamReaderInterface
{
    public function offset(): int;
    public function setOffset(int $newOffset): self;

    public function isEOF(): bool;

    public function char(): string;
    public function byte(): int;
    public function signedByte(): int;
    public function short(): int;
    public function signedShort(): int;
    public function dword(): int;
    public function signedDword(): int;
    public function read(int $length): string;
}
