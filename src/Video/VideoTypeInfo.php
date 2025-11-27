<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Video;

class VideoTypeInfo
{
    public const TEXT_MODE_CHAR_WIDTH = 8;
    public const TEXT_MODE_CHAR_HEIGHT = 16;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $colors,
        public readonly VideoColorType $videoColorType,
        public readonly bool $isTextMode = false,
    ) {}

    public function pixelWidth(): int
    {
        return $this->isTextMode
            ? $this->width * self::TEXT_MODE_CHAR_WIDTH
            : $this->width;
    }

    public function pixelHeight(): int
    {
        return $this->isTextMode
            ? $this->height * self::TEXT_MODE_CHAR_HEIGHT
            : $this->height;
    }
}
