<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

use PHPMachineEmulator\Exception\LogicBoardException;

class MediaContext implements MediaContextInterface
{
    /**
     * @var array<int, MediaInfoInterface>
     */
    protected array $mediaDevices = [];

    public function __construct(
        protected MediaInfoInterface $primaryMedia,
    ) {
        $this->mediaDevices[0] = $primaryMedia;
    }

    public function primary(): MediaInfoInterface
    {
        return $this->primaryMedia;
    }

    public function add(MediaInfoInterface $media, int $index): static
    {
        if ($index < 0) {
            throw new LogicBoardException('Media index must be non-negative');
        }

        $this->mediaDevices[$index] = $media;
        return $this;
    }

    public function get(int $index): MediaInfoInterface
    {
        if (!$this->has($index)) {
            throw new LogicBoardException("Media at index {$index} does not exist");
        }

        return $this->mediaDevices[$index];
    }

    public function has(int $index): bool
    {
        return isset($this->mediaDevices[$index]);
    }

    public function all(): array
    {
        return $this->mediaDevices;
    }
}
