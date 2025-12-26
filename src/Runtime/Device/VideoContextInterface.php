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

    /**
     * Enable a linear framebuffer backed by an internal buffer.
     */
    public function enableLinearFramebuffer(
        int $baseAddress,
        int $width,
        int $height,
        int $bytesPerScanLine,
        int $bitsPerPixel,
    ): void;

    public function disableLinearFramebuffer(): void;

    /**
     * @return array{base:int,width:int,height:int,bytesPerScanLine:int,bitsPerPixel:int,size:int}|null
     */
    public function linearFramebufferInfo(): ?array;

    public function linearFramebufferRead(int $address, int $width): ?int;

    /**
     * @param int $width Bit width (8/16/32/64)
     * @return bool True if handled
     */
    public function linearFramebufferWrite(int $address, int $value, int $width): bool;
}
