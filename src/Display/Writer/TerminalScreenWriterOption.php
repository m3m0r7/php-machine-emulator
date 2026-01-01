<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

final class TerminalScreenWriterOption
{
    public function __construct(
        public readonly bool $silentOutput = false,
        public readonly int $terminalCols = 80,
        public readonly int $terminalRows = 24,
        public readonly bool $supportsTrueColor = false,
    ) {
    }
}
