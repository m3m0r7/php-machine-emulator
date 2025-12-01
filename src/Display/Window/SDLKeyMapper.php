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
     * SDL scancode value => [BIOS scancode, ASCII (normal), ASCII (shift)]
     */
    private const SCANCODE_MAP = [
        // Letters A-Z (SDL 4-29)
        4 => [0x1E, 0x61, 0x41], // A: a, A
        5 => [0x30, 0x62, 0x42], // B
        6 => [0x2E, 0x63, 0x43], // C
        7 => [0x20, 0x64, 0x44], // D
        8 => [0x12, 0x65, 0x45], // E
        9 => [0x21, 0x66, 0x46], // F
        10 => [0x22, 0x67, 0x47], // G
        11 => [0x23, 0x68, 0x48], // H
        12 => [0x17, 0x69, 0x49], // I
        13 => [0x24, 0x6A, 0x4A], // J
        14 => [0x25, 0x6B, 0x4B], // K
        15 => [0x26, 0x6C, 0x4C], // L
        16 => [0x32, 0x6D, 0x4D], // M
        17 => [0x31, 0x6E, 0x4E], // N
        18 => [0x18, 0x6F, 0x4F], // O
        19 => [0x19, 0x70, 0x50], // P
        20 => [0x10, 0x71, 0x51], // Q
        21 => [0x13, 0x72, 0x52], // R
        22 => [0x1F, 0x73, 0x53], // S
        23 => [0x14, 0x74, 0x54], // T
        24 => [0x16, 0x75, 0x55], // U
        25 => [0x2F, 0x76, 0x56], // V
        26 => [0x11, 0x77, 0x57], // W
        27 => [0x2D, 0x78, 0x58], // X
        28 => [0x15, 0x79, 0x59], // Y
        29 => [0x2C, 0x7A, 0x5A], // Z

        // Numbers 1-0 (SDL 30-39)
        30 => [0x02, 0x31, 0x21], // 1, !
        31 => [0x03, 0x32, 0x40], // 2, @
        32 => [0x04, 0x33, 0x23], // 3, #
        33 => [0x05, 0x34, 0x24], // 4, $
        34 => [0x06, 0x35, 0x25], // 5, %
        35 => [0x07, 0x36, 0x5E], // 6, ^
        36 => [0x08, 0x37, 0x26], // 7, &
        37 => [0x09, 0x38, 0x2A], // 8, *
        38 => [0x0A, 0x39, 0x28], // 9, (
        39 => [0x0B, 0x30, 0x29], // 0, )

        // Special keys
        40 => [0x1C, 0x0D, 0x0D], // RETURN: Enter
        41 => [0x01, 0x1B, 0x1B], // ESCAPE: Escape
        42 => [0x0E, 0x08, 0x08], // BACKSPACE: Backspace
        43 => [0x0F, 0x09, 0x00], // TAB: Tab (shift-tab has scancode 0x0F, ASCII 0)
        44 => [0x39, 0x20, 0x20], // SPACE: Space

        // Arrow keys (extended keys - ASCII = 0, use scan code)
        79 => [0x4D, 0x00, 0x00], // RIGHT
        80 => [0x4B, 0x00, 0x00], // LEFT
        81 => [0x50, 0x00, 0x00], // DOWN
        82 => [0x48, 0x00, 0x00], // UP

        // Function keys
        58 => [0x3B, 0x00, 0x00], // F1
        59 => [0x3C, 0x00, 0x00], // F2
        60 => [0x3D, 0x00, 0x00], // F3
        61 => [0x3E, 0x00, 0x00], // F4
        62 => [0x3F, 0x00, 0x00], // F5
        63 => [0x40, 0x00, 0x00], // F6
        64 => [0x41, 0x00, 0x00], // F7
        65 => [0x42, 0x00, 0x00], // F8
        66 => [0x43, 0x00, 0x00], // F9
        67 => [0x44, 0x00, 0x00], // F10
        68 => [0x57, 0x00, 0x00], // F11
        69 => [0x58, 0x00, 0x00], // F12
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
