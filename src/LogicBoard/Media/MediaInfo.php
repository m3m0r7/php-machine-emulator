<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Stream\BootableStreamInterface;

class MediaInfo implements MediaInfoInterface
{
    public function __construct(
        protected BootableStreamInterface $stream,
        protected BootType $bootType = BootType::BOOT_SIGNATURE,
        protected MediaType $mediaType = MediaType::CD,
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
}
