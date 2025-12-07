<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Interface for keyboard device context (state holder).
 * Holds keyboard state only - processing logic is in DeviceManager.
 */
interface KeyboardContextInterface extends DeviceContextInterface
{
    /**
     * Check if CPU is waiting for key input (INT 16h AH=0x00/0x10).
     */
    public function isWaitingForKey(): bool;

    /**
     * Set waiting state for key input.
     *
     * @param bool $waiting Whether waiting for key
     * @param int $function The INT 16h function (AH value) that initiated the wait
     */
    public function setWaitingForKey(bool $waiting, int $function = 0x00): void;

    /**
     * Get the INT 16h function that initiated the wait.
     */
    public function getWaitingFunction(): int;

    /**
     * Enqueue a key press.
     *
     * @param int $scancode BIOS scan code (high byte of AX)
     * @param int $ascii ASCII code (low byte of AX)
     */
    public function enqueueKey(int $scancode, int $ascii): void;

    /**
     * Dequeue a key press.
     *
     * @return array{scancode: int, ascii: int}|null Key data or null if empty
     */
    public function dequeueKey(): ?array;

    /**
     * Peek at the next key without removing it.
     *
     * @return array{scancode: int, ascii: int}|null Key data or null if empty
     */
    public function peekKey(): ?array;

    /**
     * Check if there are keys in the buffer.
     */
    public function hasKey(): bool;

    /**
     * Clear the key buffer.
     */
    public function clearBuffer(): void;
}
