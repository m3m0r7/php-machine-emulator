<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

/**
 * VGA 16-color palette (standard CGA/EGA/VGA colors).
 */
enum VgaPaletteColor: int
{
    case Black = 0x00;
    case Blue = 0x01;
    case Green = 0x02;
    case Cyan = 0x03;
    case Red = 0x04;
    case Magenta = 0x05;
    case Brown = 0x06;
    case LightGray = 0x07;
    case DarkGray = 0x08;
    case LightBlue = 0x09;
    case LightGreen = 0x0A;
    case LightCyan = 0x0B;
    case LightRed = 0x0C;
    case LightMagenta = 0x0D;
    case Yellow = 0x0E;
    case White = 0x0F;

    /**
     * Get RGB values for this color.
     *
     * @return array{int, int, int} RGB values [red, green, blue]
     */
    public function rgb(): array
    {
        return match ($this) {
            self::Black => [0x00, 0x00, 0x00],
            self::Blue => [0x00, 0x00, 0xAA],
            self::Green => [0x00, 0xAA, 0x00],
            self::Cyan => [0x00, 0xAA, 0xAA],
            self::Red => [0xAA, 0x00, 0x00],
            self::Magenta => [0xAA, 0x00, 0xAA],
            self::Brown => [0xAA, 0x55, 0x00],
            self::LightGray => [0xAA, 0xAA, 0xAA],
            self::DarkGray => [0x55, 0x55, 0x55],
            self::LightBlue => [0x55, 0x55, 0xFF],
            self::LightGreen => [0x55, 0xFF, 0x55],
            self::LightCyan => [0x55, 0xFF, 0xFF],
            self::LightRed => [0xFF, 0x55, 0x55],
            self::LightMagenta => [0xFF, 0x55, 0xFF],
            self::Yellow => [0xFF, 0xFF, 0x55],
            self::White => [0xFF, 0xFF, 0xFF],
        };
    }

    /**
     * Create a Color instance from this palette color.
     */
    public function toColor(): Color
    {
        $rgb = $this->rgb();
        return new Color($rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * Get a palette color by index, with fallback to LightGray.
     */
    public static function fromIndex(int $index): self
    {
        return self::tryFrom($index & 0x0F) ?? self::LightGray;
    }
}
