<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

use FFI;

class WindowKeyboard
{
    /** @var array<int, bool> Track keys that have been consumed (waiting for release) */
    private array $consumedKeys = [];

    public function __construct(
        protected FFI $ffi,
    ) {
    }

    /**
     * Check if a specific key is currently pressed
     *
     * @param SDLScancode $scancode SDL scancode
     * @return bool True if key is pressed
     */
    public function isKeyPressed(SDLScancode $scancode): bool
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);
        return $keyboardState[$scancode->value] !== 0;
    }

    /**
     * Get raw keyboard state array pointer
     *
     * @return FFI\CData Pointer to keyboard state array
     */
    public function getKeyboardState(): FFI\CData
    {
        return $this->ffi->SDL_GetKeyboardState(null);
    }

    /**
     * Get all currently pressed keys as SDL scancodes
     *
     * @return SDLScancode[] Array of SDL scancodes that are currently pressed
     */
    public function getPressedKeys(): array
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);
        $pressed = [];

        foreach (SDLScancode::cases() as $scancode) {
            if ($keyboardState[$scancode->value] !== 0) {
                $pressed[] = $scancode;
            }
        }

        return $pressed;
    }

    /**
     * Check if shift key is currently pressed
     */
    public function isShiftPressed(): bool
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);
        return $keyboardState[SDLScancode::LSHIFT->value] !== 0
            || $keyboardState[SDLScancode::RSHIFT->value] !== 0;
    }

    /**
     * Poll for a single key press and return BIOS key code
     *
     * This returns the first pressed key found and its BIOS representation.
     * For use with INT 16h AH=00h (wait for keypress)
     *
     * A key is only returned once per press - subsequent calls will return null
     * until the key is released and pressed again.
     *
     * @return int|null AX value (AH=scan code, AL=ASCII) or null if no key pressed
     */
    public function pollKeyPress(): ?int
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);

        // First, clear consumed keys that have been released
        foreach ($this->consumedKeys as $scancodeValue => $consumed) {
            if ($keyboardState[$scancodeValue] === 0) {
                unset($this->consumedKeys[$scancodeValue]);
            }
        }

        // Find a newly pressed key (not already consumed)
        foreach (SDLScancode::cases() as $scancode) {
            if ($keyboardState[$scancode->value] !== 0 && !$scancode->isModifier()) {
                // Skip if this key was already consumed (still held down)
                if (isset($this->consumedKeys[$scancode->value])) {
                    continue;
                }

                // Mark as consumed so it won't repeat
                $this->consumedKeys[$scancode->value] = true;

                return SDLKeyMapper::toBiosKeyCode($scancode->value, $this->isShiftPressed());
            }
        }

        return null;
    }
}
