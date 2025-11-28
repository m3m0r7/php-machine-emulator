<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

/**
 * Maps SDL scancodes to BIOS keyboard scan codes and ASCII values
 *
 * INT 16h returns: AH = BIOS scan code, AL = ASCII character
 */
class SDLKeyMapper
{
    /**
     * SDL scancode => [BIOS scancode, ASCII (normal), ASCII (shift)]
     */
    private const SCANCODE_MAP = [
        // Letters A-Z (SDL 4-29)
        Window::SDL_SCANCODE_A => [0x1E, 0x61, 0x41], // a, A
        Window::SDL_SCANCODE_B => [0x30, 0x62, 0x42],
        Window::SDL_SCANCODE_C => [0x2E, 0x63, 0x43],
        Window::SDL_SCANCODE_D => [0x20, 0x64, 0x44],
        Window::SDL_SCANCODE_E => [0x12, 0x65, 0x45],
        Window::SDL_SCANCODE_F => [0x21, 0x66, 0x46],
        Window::SDL_SCANCODE_G => [0x22, 0x67, 0x47],
        Window::SDL_SCANCODE_H => [0x23, 0x68, 0x48],
        Window::SDL_SCANCODE_I => [0x17, 0x69, 0x49],
        Window::SDL_SCANCODE_J => [0x24, 0x6A, 0x4A],
        Window::SDL_SCANCODE_K => [0x25, 0x6B, 0x4B],
        Window::SDL_SCANCODE_L => [0x26, 0x6C, 0x4C],
        Window::SDL_SCANCODE_M => [0x32, 0x6D, 0x4D],
        Window::SDL_SCANCODE_N => [0x31, 0x6E, 0x4E],
        Window::SDL_SCANCODE_O => [0x18, 0x6F, 0x4F],
        Window::SDL_SCANCODE_P => [0x19, 0x70, 0x50],
        Window::SDL_SCANCODE_Q => [0x10, 0x71, 0x51],
        Window::SDL_SCANCODE_R => [0x13, 0x72, 0x52],
        Window::SDL_SCANCODE_S => [0x1F, 0x73, 0x53],
        Window::SDL_SCANCODE_T => [0x14, 0x74, 0x54],
        Window::SDL_SCANCODE_U => [0x16, 0x75, 0x55],
        Window::SDL_SCANCODE_V => [0x2F, 0x76, 0x56],
        Window::SDL_SCANCODE_W => [0x11, 0x77, 0x57],
        Window::SDL_SCANCODE_X => [0x2D, 0x78, 0x58],
        Window::SDL_SCANCODE_Y => [0x15, 0x79, 0x59],
        Window::SDL_SCANCODE_Z => [0x2C, 0x7A, 0x5A],

        // Numbers 1-0 (SDL 30-39)
        Window::SDL_SCANCODE_1 => [0x02, 0x31, 0x21], // 1, !
        Window::SDL_SCANCODE_2 => [0x03, 0x32, 0x40], // 2, @
        Window::SDL_SCANCODE_3 => [0x04, 0x33, 0x23], // 3, #
        Window::SDL_SCANCODE_4 => [0x05, 0x34, 0x24], // 4, $
        Window::SDL_SCANCODE_5 => [0x06, 0x35, 0x25], // 5, %
        Window::SDL_SCANCODE_6 => [0x07, 0x36, 0x5E], // 6, ^
        Window::SDL_SCANCODE_7 => [0x08, 0x37, 0x26], // 7, &
        Window::SDL_SCANCODE_8 => [0x09, 0x38, 0x2A], // 8, *
        Window::SDL_SCANCODE_9 => [0x0A, 0x39, 0x28], // 9, (
        Window::SDL_SCANCODE_0 => [0x0B, 0x30, 0x29], // 0, )

        // Special keys
        Window::SDL_SCANCODE_RETURN => [0x1C, 0x0D, 0x0D],    // Enter
        Window::SDL_SCANCODE_ESCAPE => [0x01, 0x1B, 0x1B],    // Escape
        Window::SDL_SCANCODE_BACKSPACE => [0x0E, 0x08, 0x08], // Backspace
        Window::SDL_SCANCODE_TAB => [0x0F, 0x09, 0x00],       // Tab (shift-tab has scancode 0x0F, ASCII 0)
        Window::SDL_SCANCODE_SPACE => [0x39, 0x20, 0x20],     // Space

        // Arrow keys (extended keys - ASCII = 0, use scan code)
        Window::SDL_SCANCODE_RIGHT => [0x4D, 0x00, 0x00],
        Window::SDL_SCANCODE_LEFT => [0x4B, 0x00, 0x00],
        Window::SDL_SCANCODE_DOWN => [0x50, 0x00, 0x00],
        Window::SDL_SCANCODE_UP => [0x48, 0x00, 0x00],

        // Function keys
        Window::SDL_SCANCODE_F1 => [0x3B, 0x00, 0x00],
        Window::SDL_SCANCODE_F2 => [0x3C, 0x00, 0x00],
        Window::SDL_SCANCODE_F3 => [0x3D, 0x00, 0x00],
        Window::SDL_SCANCODE_F4 => [0x3E, 0x00, 0x00],
        Window::SDL_SCANCODE_F5 => [0x3F, 0x00, 0x00],
        Window::SDL_SCANCODE_F6 => [0x40, 0x00, 0x00],
        Window::SDL_SCANCODE_F7 => [0x41, 0x00, 0x00],
        Window::SDL_SCANCODE_F8 => [0x42, 0x00, 0x00],
        Window::SDL_SCANCODE_F9 => [0x43, 0x00, 0x00],
        Window::SDL_SCANCODE_F10 => [0x44, 0x00, 0x00],
        Window::SDL_SCANCODE_F11 => [0x57, 0x00, 0x00],
        Window::SDL_SCANCODE_F12 => [0x58, 0x00, 0x00],
    ];

    /**
     * Convert SDL scancode to BIOS keyboard data (AX value for INT 16h)
     *
     * @param int $sdlScancode SDL scancode
     * @param bool $shiftPressed Whether shift is held
     * @return int|null AX value (AH=scan code, AL=ASCII) or null if unmapped
     */
    public static function toBiosKeyCode(int $sdlScancode, bool $shiftPressed = false): ?int
    {
        if (!isset(self::SCANCODE_MAP[$sdlScancode])) {
            return null;
        }

        [$biosScancode, $asciiNormal, $asciiShift] = self::SCANCODE_MAP[$sdlScancode];
        $ascii = $shiftPressed ? $asciiShift : $asciiNormal;

        // AH = BIOS scan code, AL = ASCII character
        return ($biosScancode << 8) | $ascii;
    }

    /**
     * Get ASCII character from SDL scancode
     *
     * @param int $sdlScancode SDL scancode
     * @param bool $shiftPressed Whether shift is held
     * @return int|null ASCII value or null if unmapped
     */
    public static function toAscii(int $sdlScancode, bool $shiftPressed = false): ?int
    {
        if (!isset(self::SCANCODE_MAP[$sdlScancode])) {
            return null;
        }

        [, $asciiNormal, $asciiShift] = self::SCANCODE_MAP[$sdlScancode];
        return $shiftPressed ? $asciiShift : $asciiNormal;
    }

    /**
     * Get BIOS scan code from SDL scancode
     *
     * @param int $sdlScancode SDL scancode
     * @return int|null BIOS scan code or null if unmapped
     */
    public static function toBiosScancode(int $sdlScancode): ?int
    {
        if (!isset(self::SCANCODE_MAP[$sdlScancode])) {
            return null;
        }

        return self::SCANCODE_MAP[$sdlScancode][0];
    }

    /**
     * Get all mapped SDL scancodes
     *
     * @return int[]
     */
    public static function getMappedScancodes(): array
    {
        return array_keys(self::SCANCODE_MAP);
    }
}
