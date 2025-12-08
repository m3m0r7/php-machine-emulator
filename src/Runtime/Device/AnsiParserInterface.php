<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Interface for ANSI escape sequence parser.
 */
interface AnsiParserInterface
{
    public const STATE_NORMAL = 0;
    public const STATE_ESC = 1;      // Got ESC (0x1B)
    public const STATE_CSI = 2;      // Got ESC [

    /**
     * Get current parser state.
     */
    public function getState(): int;

    /**
     * Reset parser state to normal.
     */
    public function reset(): void;

    /**
     * Process a character through the ANSI parser.
     * Returns true if the character was consumed by the parser (part of an escape sequence).
     * Returns false if the character should be handled normally.
     */
    public function processChar(int $char, RuntimeInterface $runtime, VideoContextInterface $videoContext): bool;

    /**
     * Get the current buffer contents (for CSI parameters).
     */
    public function getBuffer(): string;
}
