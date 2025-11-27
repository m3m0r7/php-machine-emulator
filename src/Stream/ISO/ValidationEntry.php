<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class ValidationEntry
{
    public readonly int $headerID;
    public readonly int $platformID;
    public readonly string $idString;
    public readonly int $checksum;
    public readonly int $keyBytes;

    public function __construct(string $data)
    {
        // Header ID (byte 0) - must be 0x01
        $this->headerID = ord($data[0]);

        // Platform ID (byte 1)
        $this->platformID = ord($data[1]);

        // ID String (bytes 4-27)
        $this->idString = trim(substr($data, 4, 24));

        // Checksum (bytes 28-29, little-endian)
        $this->checksum = unpack('v', substr($data, 28, 2))[1];

        // Key bytes (bytes 30-31) - must be 0x55AA
        $this->keyBytes = unpack('v', substr($data, 30, 2))[1];
    }

    public function isValid(): bool
    {
        return $this->headerID === 0x01 && $this->keyBytes === 0xAA55;
    }

    public function platformName(): string
    {
        return match ($this->platformID) {
            ElTorito::PLATFORM_X86 => 'x86',
            ElTorito::PLATFORM_PPC => 'PowerPC',
            ElTorito::PLATFORM_MAC => 'Mac',
            ElTorito::PLATFORM_EFI => 'EFI',
            default => 'Unknown',
        };
    }
}
