<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Video\VideoColorType;
use PHPMachineEmulator\Video\VideoInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class VideoInterrupt implements VideoInterface
{
    public function videoTypeFlagAddress(): int
    {
        return 0xFF0000;
    }

    public function supportedVideoModes(): array
    {
        return [
            // NOTE: Text Modes
            0x00 => new VideoTypeInfo(40, 25, 16, VideoColorType::COLOR),
            0x01 => new VideoTypeInfo(40, 25, 16, VideoColorType::MONOCHROME),
            0x02 => new VideoTypeInfo(80, 25, 16, VideoColorType::COLOR),
            0x03 => new VideoTypeInfo(80, 25, 16, VideoColorType::MONOCHROME),
            0x07 => new VideoTypeInfo(80, 25, 2, VideoColorType::MONOCHROME),

            // NOTE: Graphic Modes
            0x04 => new VideoTypeInfo(320, 200, 4, VideoColorType::COLOR),
            0x05 => new VideoTypeInfo(320, 200, 4, VideoColorType::MONOCHROME),
            0x06 => new VideoTypeInfo(640, 200, 2, VideoColorType::MONOCHROME),
            0x0D => new VideoTypeInfo(320, 200, 16, VideoColorType::COLOR),
            0x0E => new VideoTypeInfo(640, 200, 16, VideoColorType::MONOCHROME),
            0x0F => new VideoTypeInfo(640, 350, 2, VideoColorType::MONOCHROME),
            0x10 => new VideoTypeInfo(640, 350, 16, VideoColorType::COLOR),
            0x11 => new VideoTypeInfo(640, 480, 2, VideoColorType::MONOCHROME),
            0x12 => new VideoTypeInfo(640, 480, 16, VideoColorType::COLOR),
            0x13 => new VideoTypeInfo(320, 200, 256, VideoColorType::COLOR),
        ];
    }
}
