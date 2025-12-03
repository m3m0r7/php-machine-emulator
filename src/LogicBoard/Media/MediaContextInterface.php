<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

interface MediaContextInterface
{
    /**
     * Get the primary (bootable) media.
     */
    public function primary(): MediaInfoInterface;

    /**
     * Add a media device at the specified index.
     *
     * @return static
     */
    public function add(MediaInfoInterface $media, int $index): static;

    /**
     * Get a media device by index.
     */
    public function get(int $index): MediaInfoInterface;

    /**
     * Check if a media device exists at the specified index.
     */
    public function has(int $index): bool;

    /**
     * Get all media devices.
     *
     * @return array<int, MediaInfoInterface>
     */
    public function all(): array;
}
