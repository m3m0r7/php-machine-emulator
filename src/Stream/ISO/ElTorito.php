<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

use PHPMachineEmulator\Exception\StreamReaderException;

class ElTorito
{
    // Boot Media Types
    public const MEDIA_NO_EMULATION = 0x00;
    public const MEDIA_FLOPPY_1_2M = 0x01;
    public const MEDIA_FLOPPY_1_44M = 0x02;
    public const MEDIA_FLOPPY_2_88M = 0x03;
    public const MEDIA_HARD_DISK = 0x04;

    // Platform IDs
    public const PLATFORM_X86 = 0x00;
    public const PLATFORM_PPC = 0x01;
    public const PLATFORM_MAC = 0x02;
    public const PLATFORM_EFI = 0xEF;

    private ?ValidationEntry $validationEntry = null;
    private ?InitialEntry $initialEntry = null;
    private array $sectionEntries = [];

    public function __construct(private ISO9660 $iso, private int $catalogSector)
    {
        $this->parseCatalog();
    }

    private function parseCatalog(): void
    {
        $this->iso->seekSector($this->catalogSector);
        $data = $this->iso->readSector();

        if ($data === false || strlen($data) < 64) {
            throw new StreamReaderException('Cannot read El Torito boot catalog');
        }

        // Validation Entry (first 32 bytes)
        $this->validationEntry = new ValidationEntry(substr($data, 0, 32));

        if (!$this->validationEntry->isValid()) {
            throw new StreamReaderException('Invalid El Torito validation entry');
        }

        // Initial/Default Entry (next 32 bytes)
        $this->initialEntry = new InitialEntry(substr($data, 32, 32));

        // Parse section entries if present
        $offset = 64;
        while ($offset + 32 <= strlen($data)) {
            $entryData = substr($data, $offset, 32);
            $headerIndicator = ord($entryData[0]);

            if ($headerIndicator === 0x00) {
                // End of entries
                break;
            }

            if ($headerIndicator === 0x90 || $headerIndicator === 0x91) {
                // Section header
                $this->sectionEntries[] = new SectionHeader($entryData);
            }

            $offset += 32;
        }
    }

    public function validationEntry(): ?ValidationEntry
    {
        return $this->validationEntry;
    }

    public function initialEntry(): ?InitialEntry
    {
        return $this->initialEntry;
    }

    public function sectionEntries(): array
    {
        return $this->sectionEntries;
    }

    public function getBootImage(): ?BootImage
    {
        if ($this->initialEntry === null || !$this->initialEntry->isBootable()) {
            return null;
        }

        return new BootImage(
            $this->iso,
            $this->initialEntry->loadRBA(),
            $this->initialEntry->sectorCount(),
            $this->initialEntry->mediaType(),
            $this->initialEntry->loadSegment()
        );
    }
}
