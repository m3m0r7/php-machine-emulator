<?php

declare(strict_types=1);

namespace PHPMachineEmulator\IO;

interface InputInterface
{
    public function key(): string;
    public function text(): string;
    public function byte(): int;
    public function bytes(): array;
}
