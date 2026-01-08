<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Exception\StreamReaderException;

/**
 * ISO 9660 stream with El Torito boot support.
 */
class ISOStream implements ISOStreamInterface
{
    private ISO9660 $iso;
    private ElTorito $elTorito;
    private BootImage $bootImage;

    public function __construct(public readonly string $path, private ?int $platformId = null)
    {
        $this->iso = new ISO9660($path);

        if (!$this->iso->hasElTorito()) {
            throw new StreamReaderException('ISO does not contain El Torito boot record');
        }

        $bootRecord = $this->iso->bootRecord();
        $this->elTorito = new ElTorito($this->iso, $bootRecord->bootCatalogSector);
        $bootImage = $this->platformId !== null
            ? $this->elTorito->getBootImageForPlatform($this->platformId)
            : null;
        $this->bootImage = $bootImage ?? $this->elTorito->getBootImage();
    }

    public function iso(): ISO9660
    {
        return $this->iso;
    }

    public function elTorito(): ElTorito
    {
        return $this->elTorito;
    }

    public function bootImage(): BootImage
    {
        return $this->bootImage;
    }
}
