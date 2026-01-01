<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\ISO\ElTorito;

class MediaInfo implements MediaInfoInterface
{
    public function __construct(
        protected BootableStreamInterface $stream,
        protected BootType $bootType = BootType::BOOT_SIGNATURE,
        protected MediaType $mediaType = MediaType::CD,
        protected ?DriveType $driveType = null,
    ) {
    }

    public function stream(): BootableStreamInterface
    {
        return $this->stream;
    }

    public function bootType(): BootType
    {
        return $this->bootType;
    }

    public function mediaType(): MediaType
    {
        return $this->mediaType;
    }

    public function driveType(): DriveType
    {
        if ($this->driveType !== null) {
            return $this->driveType;
        }

        $this->driveType = $this->inferDriveType();
        return $this->driveType;
    }

    private function inferDriveType(): DriveType
    {
        if ($this->bootType === BootType::EL_TORITO) {
            if ($this->stream->isNoEmulation()) {
                return DriveType::CD_ROM;
            }

            $bootImage = $this->stream->bootImage();
            if ($bootImage !== null) {
                $mediaType = $bootImage->mediaType();
                if (
                    in_array($mediaType, [
                    ElTorito::MEDIA_FLOPPY_1_2M,
                    ElTorito::MEDIA_FLOPPY_1_44M,
                    ElTorito::MEDIA_FLOPPY_2_88M,
                    ], true)
                ) {
                    return DriveType::FLOPPY;
                }
                if ($mediaType === ElTorito::MEDIA_HARD_DISK) {
                    return DriveType::HARD_DISK;
                }
            }

            return DriveType::CD_ROM;
        }

        return DriveType::HARD_DISK;
    }
}
