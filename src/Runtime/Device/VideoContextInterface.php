<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Interface for video device context.
 * Holds video state including cursor position, current mode, and ANSI parser state.
 */
interface VideoContextInterface extends DeviceContextInterface
{
    /**
     * Get current video mode.
     */
    public function getCurrentMode(): int;

    /**
     * Set current video mode.
     */
    public function setCurrentMode(int $mode): void;

    /**
     * Get cursor row position.
     */
    public function getCursorRow(): int;

    /**
     * Get cursor column position.
     */
    public function getCursorCol(): int;

    /**
     * Set cursor position.
     */
    public function setCursorPosition(int $row, int $col): void;

    /**
     * Get the ANSI escape sequence parser.
     */
    public function ansiParser(): AnsiParserInterface;
}
