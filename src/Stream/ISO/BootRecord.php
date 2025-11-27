<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class BootRecord
{
    public const EL_TORITO_IDENTIFIER = 'EL TORITO SPECIFICATION';

    public readonly string $bootSystemIdentifier;
    public readonly int $bootCatalogSector;

    public function __construct(string $data)
    {
        // Boot System Identifier (bytes 7-38)
        $this->bootSystemIdentifier = trim(substr($data, 7, 32));

        // Boot Catalog pointer (bytes 71-74, little-endian)
        $this->bootCatalogSector = unpack('V', substr($data, 71, 4))[1];
    }

    public function isElTorito(): bool
    {
        return str_starts_with($this->bootSystemIdentifier, 'EL TORITO');
    }
}
