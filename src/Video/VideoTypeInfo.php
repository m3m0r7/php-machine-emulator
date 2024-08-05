<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Video;

class VideoTypeInfo
{
    public function __construct(public readonly int $width, public readonly int $height, public readonly int $colors, public readonly VideoColorType $videoColorType)
    {}
}
