<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Pixel;

class Color implements ColorInterface
{
    public function __construct(protected int $red, protected int $green, protected int $blue)
    {
    }

    public function red(): int
    {
        return $this->red;
    }

    public function blue(): int
    {
        return $this->blue;
    }

    public function green(): int
    {
        return $this->green;
    }

    public static function fromANSI(int $color): self
    {
        return match ($color) {
            0x0 => self::asBlack(),
            0x1 => self::asBlue(),
            0x2 => self::asGreen(),
            0x3 => self::asCyan(),
            0x4 => self::asRed(),
            0x5 => self::asMagenta(),
            0x6 => self::asBrown(),
            0x7 => self::asLightGray(),
            0x8 => self::asDarkGray(),
            0x9 => self::asLightBlue(),
            0xA => self::asLightGreen(),
            0xB => self::asLightCyan(),
            0xC => self::asLightRed(),
            0xD => self::asLightMagenta(),
            0xE => self::asYellow(),
            default => self::asWhite(),
        };
    }

    public static function asWhite(): self
    {
        static $color = null;
        return $color ??= new self(0xFF, 0xFF, 0xFF);
    }

    public static function asBlack(): self
    {
        static $color = null;
        return $color ??= new self(0x00, 0x00, 0x00);
    }

    public static function asLightGray(): self
    {
        static $color = null;
        return $color ??= new self(0xAA, 0xAA, 0xAA);
    }

    public static function asDarkGray(): self
    {
        static $color = null;
        return $color ??= new self(0x55, 0x55, 0x55);
    }

    public static function asLightRed(): self
    {
        static $color = null;
        return $color ??= new self(0xFF, 0x55, 0x55);
    }

    public static function asRed(): self
    {
        static $color = null;
        return $color ??= new self(0xAA, 0x00, 0x00);
    }

    public static function asLightGreen(): self
    {
        static $color = null;
        return $color ??= new self(0x55, 0xFF, 0x55);
    }

    public static function asGreen(): self
    {
        static $color = null;
        return $color ??= new self(0x00, 0xAA, 0x00);
    }

    public static function asYellow(): self
    {
        static $color = null;
        return $color ??= new self(0xFF, 0xFF, 0x55);
    }

    public static function asLightBlue(): self
    {
        static $color = null;
        return $color ??= new self(0x55, 0x55, 0xAA);
    }

    public static function asBlue(): self
    {
        static $color = null;
        return $color ??= new self(0x00, 0x00, 0xAA);
    }

    public static function asLightMagenta(): self
    {
        static $color = null;
        return $color ??= new self(0xFF, 0x55, 0xFF);
    }

    public static function asMagenta(): self
    {
        static $color = null;
        return $color ??= new self(0xAA, 0x00, 0xAA);
    }

    public static function asBrown(): self
    {
        static $color = null;
        return $color ??= new self(0xAA, 0x55, 0x00);
    }

    public static function asLightCyan(): self
    {
        static $color = null;
        return $color ??= new self(0x55, 0xFF, 0xFF);
    }

    public static function asCyan(): self
    {
        static $color = null;
        return $color ??= new self(0x00, 0xAA, 0xAA);
    }
}
