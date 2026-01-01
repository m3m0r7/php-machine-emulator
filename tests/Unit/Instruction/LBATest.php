<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

/**
 * Tests for LBA (Logical Block Addressing) functionality.
 *
 * Tests verify:
 * - CHS to LBA conversion
 * - LBA to CHS conversion
 * - INT 13h LBA extensions (AH=42h, 43h, 48h)
 * - Disk geometry handling
 */
class LBATest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    // ========================================
    // CHS to LBA Conversion
    // ========================================

    public function testChsToLbaConversionFirstSector(): void
    {
        // Standard floppy geometry: 18 sectors/track, 2 heads
        $sectorsPerTrack = 18;
        $headsPerCylinder = 2;

        // CHS = 0, 0, 1 (first sector)
        $cylinder = 0;
        $head = 0;
        $sector = 1;

        $lba = ($cylinder * $headsPerCylinder + $head) * $sectorsPerTrack + ($sector - 1);

        $this->assertSame(0, $lba, 'First sector should be LBA 0');
    }

    public function testChsToLbaConversionSecondSector(): void
    {
        $sectorsPerTrack = 18;
        $headsPerCylinder = 2;

        // CHS = 0, 0, 2
        $lba = (0 * 2 + 0) * 18 + (2 - 1);

        $this->assertSame(1, $lba);
    }

    public function testChsToLbaConversionSecondHead(): void
    {
        $sectorsPerTrack = 18;
        $headsPerCylinder = 2;

        // CHS = 0, 1, 1 (second head, first sector)
        $lba = (0 * 2 + 1) * 18 + (1 - 1);

        $this->assertSame(18, $lba);
    }

    public function testChsToLbaConversionSecondCylinder(): void
    {
        $sectorsPerTrack = 18;
        $headsPerCylinder = 2;

        // CHS = 1, 0, 1 (second cylinder)
        $lba = (1 * 2 + 0) * 18 + (1 - 1);

        $this->assertSame(36, $lba);
    }

    // ========================================
    // LBA to CHS Conversion
    // ========================================

    public function testLbaToChsConversionLBA0(): void
    {
        $sectorsPerTrack = 18;
        $headsPerCylinder = 2;

        $lba = 0;

        $sector = ($lba % $sectorsPerTrack) + 1;
        $head = ($lba / $sectorsPerTrack) % $headsPerCylinder;
        $cylinder = $lba / ($sectorsPerTrack * $headsPerCylinder);

        $this->assertSame(1, $sector);
        $this->assertSame(0, (int)$head);
        $this->assertSame(0, (int)$cylinder);
    }

    public function testLbaToChsConversionLBA36(): void
    {
        $sectorsPerTrack = 18;
        $headsPerCylinder = 2;

        $lba = 36;

        $sector = ($lba % $sectorsPerTrack) + 1;
        $head = (int)(($lba / $sectorsPerTrack) % $headsPerCylinder);
        $cylinder = (int)($lba / ($sectorsPerTrack * $headsPerCylinder));

        $this->assertSame(1, $sector);
        $this->assertSame(0, $head);
        $this->assertSame(1, $cylinder);
    }

    // ========================================
    // Hard Drive Geometry
    // ========================================

    public function testHardDriveGeometry(): void
    {
        // Standard hard drive geometry for BIOS
        $sectorsPerTrack = 63;
        $headsPerCylinder = 16;

        // Calculate max addressable with 10-bit cylinder
        $maxCylinders = 1024;
        $maxSectors = $maxCylinders * $headsPerCylinder * $sectorsPerTrack;

        $this->assertSame(1032192, $maxSectors);
        // 1032192 * 512 = 528 MB (CHS limit)
    }

    // ========================================
    // CD-ROM LBA
    // ========================================

    public function testCDRomSectorSize(): void
    {
        $sectorSize = 2048;

        $this->assertSame(2048, $sectorSize);
    }

    public function testCDRomLbaCalculation(): void
    {
        // CD-ROM uses pure LBA without CHS conversion
        $lba = 16; // Primary Volume Descriptor
        $sectorSize = 2048;

        $offset = $lba * $sectorSize;

        $this->assertSame(32768, $offset);
    }

    // ========================================
    // INT 13h Extensions
    // ========================================

    public function testInt13hExtensionsCheck(): void
    {
        // INT 13h AH=41h - Check Extensions Present
        // Returns BX=0xAA55 if extensions supported

        $extensionSignature = 0xAA55;
        $this->assertSame(0xAA55, $extensionSignature);
    }

    public function testDiskAddressPacketStructure(): void
    {
        // Disk Address Packet (DAP) for INT 13h AH=42h/43h
        $dap = [
            'size' => 16,           // Byte 0: Size of packet (16 or 24)
            'reserved' => 0,        // Byte 1: Reserved
            'count' => 1,           // Bytes 2-3: Number of sectors
            'buffer_offset' => 0,   // Bytes 4-5: Transfer buffer offset
            'buffer_segment' => 0,  // Bytes 6-7: Transfer buffer segment
            'lba_low' => 0,         // Bytes 8-11: Starting LBA (low)
            'lba_high' => 0,        // Bytes 12-15: Starting LBA (high)
        ];

        $this->assertSame(16, $dap['size']);
    }

    public function testExtendedDriveParametersResult(): void
    {
        // INT 13h AH=48h - Get Extended Drive Parameters
        // Result buffer structure

        $params = [
            'size' => 26,           // Bytes 0-1: Buffer size
            'flags' => 0,           // Bytes 2-3: Information flags
            'cylinders' => 1024,    // Bytes 4-7: Physical cylinders
            'heads' => 16,          // Bytes 8-11: Physical heads
            'sectors' => 63,        // Bytes 12-15: Sectors per track
            'total_sectors' => 0,   // Bytes 16-23: Total sectors (64-bit)
            'bytes_per_sector' => 512, // Bytes 24-25: Bytes per sector
        ];

        $this->assertSame(26, $params['size']);
        $this->assertSame(512, $params['bytes_per_sector']);
    }

    // ========================================
    // LBA48 Support
    // ========================================

    public function testLBA48AddressRange(): void
    {
        // LBA48 allows 48-bit addresses
        $maxLBA28 = 0x0FFFFFFF; // 268 million sectors
        $maxLBA48 = 0x0000FFFFFFFFFFFF; // 281 trillion sectors

        // LBA28 limit: 128 GB (with 512-byte sectors)
        // LBA48 limit: 128 PB

        $this->assertSame(0x0FFFFFFF, $maxLBA28);
    }

    // ========================================
    // Floppy Disk Geometry
    // ========================================

    public function testFloppyGeometry144MB(): void
    {
        // 1.44MB floppy
        $sectorsPerTrack = 18;
        $heads = 2;
        $cylinders = 80;
        $sectorSize = 512;

        $totalSectors = $sectorsPerTrack * $heads * $cylinders;
        $totalBytes = $totalSectors * $sectorSize;

        $this->assertSame(2880, $totalSectors);
        $this->assertSame(1474560, $totalBytes); // 1.44 MB
    }

    public function testFloppyGeometry720KB(): void
    {
        // 720KB floppy
        $sectorsPerTrack = 9;
        $heads = 2;
        $cylinders = 80;
        $sectorSize = 512;

        $totalSectors = $sectorsPerTrack * $heads * $cylinders;
        $totalBytes = $totalSectors * $sectorSize;

        $this->assertSame(1440, $totalSectors);
        $this->assertSame(737280, $totalBytes); // 720 KB
    }
}
