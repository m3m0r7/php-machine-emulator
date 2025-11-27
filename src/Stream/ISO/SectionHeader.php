<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class SectionHeader
{
    public readonly int $headerIndicator;
    public readonly int $platformID;
    public readonly int $numSectionEntries;
    public readonly string $idString;

    public function __construct(string $data)
    {
        // Header Indicator (byte 0) - 0x90 = more headers follow, 0x91 = final header
        $this->headerIndicator = ord($data[0]);

        // Platform ID (byte 1)
        $this->platformID = ord($data[1]);

        // Number of section entries (bytes 2-3, little-endian)
        $this->numSectionEntries = unpack('v', substr($data, 2, 2))[1];

        // ID String (bytes 4-31)
        $this->idString = trim(substr($data, 4, 28));
    }

    public function isFinal(): bool
    {
        return $this->headerIndicator === 0x91;
    }
}
