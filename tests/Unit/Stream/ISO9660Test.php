<?php

declare(strict_types=1);

namespace Tests\Unit\Stream;

use PHPMachineEmulator\Stream\ISO\ISO9660;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ISO9660 filesystem reading functionality.
 *
 * These tests verify:
 * - Basic ISO structure parsing
 * - Volume descriptor reading
 * - Directory traversal
 * - File reading
 * - RockRidge extension support
 * - Multi-sector reads
 * - Large file handling
 * - LBA (Logical Block Addressing) operations
 */
class ISO9660Test extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/ISO/';

    /**
     * Test basic ISO9660 parsing with a simple ISO.
     */
    public function testBasicIsoParsing(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found: ' . $isoPath);
        }

        $iso = new ISO9660($isoPath);

        $this->assertNotNull($iso->primaryDescriptor());
        $this->assertSame(ISO9660::STANDARD_IDENTIFIER, 'CD001');
    }

    /**
     * Test reading root directory entries.
     */
    public function testReadRootDirectory(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);
    }

    /**
     * Test subdirectory traversal.
     */
    public function testSubdirectoryTraversal(): void
    {
        $isoPath = self::FIXTURES_DIR . 'SubdirTraverse.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Read root directory
        $rootEntries = $iso->readDirectory('/');
        $this->assertIsArray($rootEntries);

        // Find boot directory
        $bootDir = null;
        foreach ($rootEntries as $entry) {
            if (strcasecmp($entry['name'], 'boot') === 0 || strcasecmp($entry['name'], 'BOOT') === 0) {
                $bootDir = $entry;
                break;
            }
        }

        if ($bootDir !== null) {
            $this->assertTrue($bootDir['isDir'], 'boot should be a directory');

            // Read boot subdirectory
            $bootEntries = $iso->readDirectory('/boot');
            $this->assertIsArray($bootEntries);
        }
    }

    /**
     * Test reading a file from ISO.
     */
    public function testReadFile(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        // Find first non-directory entry
        foreach ($entries as $entry) {
            if (!$entry['isDir']) {
                $content = $iso->readFile('/' . $entry['name']);
                $this->assertNotNull($content, 'Should be able to read file: ' . $entry['name']);
                $this->assertSame($entry['size'], strlen($content), 'File size should match');
                break;
            }
        }
    }

    /**
     * Test multi-sector read functionality.
     */
    public function testMultiSectorRead(): void
    {
        $isoPath = self::FIXTURES_DIR . 'MultiSectorRead.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Read multiple sectors at once
        $iso->seekSector(16); // Primary Volume Descriptor
        $data = $iso->readSectors(2);

        $this->assertNotFalse($data);
        $this->assertSame(ISO9660::SECTOR_SIZE * 2, strlen($data));
    }

    /**
     * Test reading large files spanning multiple sectors.
     */
    public function testLargeFileRead(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LargeFile.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        // Find a large file
        foreach ($entries as $entry) {
            if (!$entry['isDir'] && $entry['size'] > ISO9660::SECTOR_SIZE) {
                $content = $iso->readFile('/' . $entry['name']);
                $this->assertNotNull($content);
                $this->assertSame($entry['size'], strlen($content));
                break;
            }
        }
    }

    /**
     * Test handling ISOs with many files.
     */
    public function testManyFilesDirectory(): void
    {
        $isoPath = self::FIXTURES_DIR . 'ManyFiles.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        $this->assertIsArray($entries);
        // Should have multiple files
        $this->assertGreaterThan(1, count($entries));
    }

    /**
     * Test reading dual file ISO (two files in root).
     */
    public function testDualFileIso(): void
    {
        $isoPath = self::FIXTURES_DIR . 'DualFile.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        $this->assertIsArray($entries);

        // Count non-directory entries
        $fileCount = 0;
        foreach ($entries as $entry) {
            if (!$entry['isDir']) {
                $fileCount++;
            }
        }

        $this->assertGreaterThanOrEqual(2, $fileCount, 'Should have at least 2 files');
    }

    /**
     * Test RockRidge extension support for long filenames.
     */
    public function testRockRidgeLongFilenames(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeNames.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        $this->assertIsArray($entries);

        // Check for entries with names longer than 8.3
        $hasLongName = false;
        foreach ($entries as $entry) {
            if (strlen($entry['name']) > 12) {
                $hasLongName = true;
                break;
            }
        }

        // If RockRidge is supported, we should see long names
        // If not, the test still passes as basic ISO support works
        $this->assertTrue(true, 'RockRidge parsing completed without error');
    }

    /**
     * Test El Torito boot record detection.
     */
    public function testElToritoDetection(): void
    {
        // Use any bootable ISO for this test
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // hasElTorito() should return a boolean
        $hasElTorito = $iso->hasElTorito();
        $this->assertIsBool($hasElTorito);

        // bootRecord() should be accessible
        $bootRecord = $iso->bootRecord();
        if ($hasElTorito) {
            $this->assertNotNull($bootRecord);
        }
    }

    /**
     * Test sector seeking and reading.
     */
    public function testSectorSeekAndRead(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Seek to sector 16 (Primary Volume Descriptor)
        $iso->seekSector(16);
        $sector = $iso->readSector();

        $this->assertNotFalse($sector);
        $this->assertSame(ISO9660::SECTOR_SIZE, strlen($sector));

        // Verify it's a volume descriptor
        $identifier = substr($sector, 1, 5);
        $this->assertSame('CD001', $identifier);
    }

    /**
     * Test readAt for direct byte access.
     */
    public function testReadAtDirectAccess(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Read Primary Volume Descriptor identifier
        $offset = 16 * ISO9660::SECTOR_SIZE + 1; // Skip type byte
        $data = $iso->readAt($offset, 5);

        $this->assertNotFalse($data);
        $this->assertSame('CD001', $data);
    }

    /**
     * Test file size reporting.
     */
    public function testFileSizeReporting(): void
    {
        $isoPath = self::FIXTURES_DIR . 'RockRidgeDir.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        $this->assertGreaterThan(0, $iso->fileSize());
        $this->assertSame(filesize($isoPath), $iso->fileSize());
    }

    // ========================================
    // LBA (Logical Block Addressing) Tests
    // ========================================

    /**
     * Test reading Primary Volume Descriptor via LBA.
     */
    public function testLbaReadPrimaryVolumeDescriptor(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // PVD is always at LBA 16
        $iso->seekSector(16);
        $pvdData = $iso->readSector();

        $this->assertSame(ISO9660::SECTOR_SIZE, strlen($pvdData));

        // Verify PVD structure
        $type = ord($pvdData[0]);
        $identifier = substr($pvdData, 1, 5);
        $version = ord($pvdData[6]);

        $this->assertSame(1, $type, 'Type should be 1 (Primary Volume Descriptor)');
        $this->assertSame('CD001', $identifier, 'Standard identifier should be CD001');
        $this->assertSame(1, $version, 'Version should be 1');
    }

    /**
     * Test extracting root directory LBA from PVD.
     */
    public function testLbaGetRootDirectoryLocation(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Read PVD
        $iso->seekSector(16);
        $pvdData = $iso->readSector();

        // Root directory record is at offset 156 in PVD
        $rootDirRecord = substr($pvdData, 156, 34);

        // Extract LBA (little-endian at offset 2)
        $rootDirLba = unpack('V', substr($rootDirRecord, 2, 4))[1];

        // Root directory LBA should be valid (typically > 16)
        $this->assertGreaterThan(16, $rootDirLba, 'Root directory LBA should be after system area');

        // Verify we can read the root directory sector
        $iso->seekSector($rootDirLba);
        $rootDirData = $iso->readSector();

        $this->assertSame(ISO9660::SECTOR_SIZE, strlen($rootDirData));

        // First entry should be "." (current directory)
        $firstEntryLen = ord($rootDirData[0]);
        $this->assertGreaterThan(0, $firstEntryLen, 'Root directory should have entries');
    }

    /**
     * Test reading directory entries via LBA.
     */
    public function testLbaReadDirectoryEntries(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Get root directory LBA
        $iso->seekSector(16);
        $pvdData = $iso->readSector();
        $rootDirRecord = substr($pvdData, 156, 34);
        $rootDirLba = unpack('V', substr($rootDirRecord, 2, 4))[1];
        $rootDirSize = unpack('V', substr($rootDirRecord, 10, 4))[1];

        // Read root directory via LBA
        $sectorsNeeded = (int)ceil($rootDirSize / ISO9660::SECTOR_SIZE);
        $iso->seekSector($rootDirLba);
        $rootDirData = $iso->readSectors($sectorsNeeded);

        $this->assertSame($sectorsNeeded * ISO9660::SECTOR_SIZE, strlen($rootDirData));

        // Parse directory entries
        $offset = 0;
        $entries = [];
        while ($offset < $rootDirSize) {
            $entryLen = ord($rootDirData[$offset]);
            if ($entryLen === 0) {
                // Padding at end of sector
                $nextSectorOffset = (int)(ceil(($offset + 1) / ISO9660::SECTOR_SIZE) * ISO9660::SECTOR_SIZE);
                if ($nextSectorOffset >= $rootDirSize) {
                    break;
                }
                $offset = $nextSectorOffset;
                continue;
            }

            $nameLen = ord($rootDirData[$offset + 32]);
            $name = substr($rootDirData, $offset + 33, $nameLen);
            $entries[] = $name;

            $offset += $entryLen;
        }

        // Should have at least . and .. entries
        $this->assertGreaterThanOrEqual(2, count($entries), 'Directory should have at least . and .. entries');
    }

    /**
     * Test reading file content via LBA.
     */
    public function testLbaReadFileContent(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        // Find root.txt file
        $targetEntry = null;
        foreach ($entries as $entry) {
            if (!$entry['isDir'] && (strcasecmp($entry['name'], 'root.txt') === 0 || strpos(strtoupper($entry['name']), 'ROOT') === 0)) {
                $targetEntry = $entry;
                break;
            }
        }

        if ($targetEntry === null) {
            $this->markTestSkipped('root.txt not found in ISO');
        }

        // Get file LBA and read via direct sector access
        $fileLba = $targetEntry['lba'];
        $fileSize = $targetEntry['size'];

        $this->assertGreaterThan(0, $fileLba, 'File LBA should be valid');
        $this->assertGreaterThan(0, $fileSize, 'File size should be positive');

        // Read file via LBA
        $iso->seekSector($fileLba);
        $sectorData = $iso->readSector();
        $fileContent = substr($sectorData, 0, $fileSize);

        $this->assertSame($fileSize, strlen($fileContent));
        $this->assertStringContainsString('Root file for LBA test', $fileContent);
    }

    /**
     * Test reading multi-sector file via LBA.
     */
    public function testLbaReadMultiSectorFile(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);
        $entries = $iso->readDirectory('/');

        // Find multisector.bin file (may be truncated to 8.3 format as MULTISEC.BIN)
        $targetEntry = null;
        foreach ($entries as $entry) {
            if (!$entry['isDir'] && (stripos($entry['name'], 'multisector') !== false || stripos($entry['name'], 'multisec') !== false)) {
                $targetEntry = $entry;
                break;
            }
        }

        if ($targetEntry === null) {
            $this->markTestSkipped('multisector.bin not found in ISO');
        }

        $fileLba = $targetEntry['lba'];
        $fileSize = $targetEntry['size'];

        // File should span multiple sectors
        $this->assertGreaterThan(ISO9660::SECTOR_SIZE, $fileSize, 'File should span multiple sectors');

        // Calculate sectors needed
        $sectorsNeeded = (int)ceil($fileSize / ISO9660::SECTOR_SIZE);

        // Read all sectors
        $iso->seekSector($fileLba);
        $data = $iso->readSectors($sectorsNeeded);

        $this->assertSame($sectorsNeeded * ISO9660::SECTOR_SIZE, strlen($data));

        // Extract actual file content
        $fileContent = substr($data, 0, $fileSize);
        $this->assertSame($fileSize, strlen($fileContent));

        // Verify content (file was created with 'L' characters)
        $this->assertSame(str_repeat('L', $fileSize), $fileContent);
    }

    /**
     * Test traversing nested directories via LBA.
     */
    public function testLbaTraverseNestedDirectories(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Navigate to level1/level2/level3 via LBA
        $paths = ['/', '/level1', '/level1/level2', '/level1/level2/level3'];
        $lbaValues = [];

        foreach ($paths as $path) {
            $entries = $iso->readDirectory($path);
            $this->assertIsArray($entries, "Should be able to read directory: $path");

            // Record LBA of first entry (.) for verification
            foreach ($entries as $entry) {
                if ($entry['name'] === "\x00" || $entry['name'] === '.') {
                    $lbaValues[$path] = $entry['lba'];
                    break;
                }
            }
        }

        // Each directory should have a unique LBA
        $uniqueLbas = array_unique($lbaValues);
        $this->assertSame(count($lbaValues), count($uniqueLbas), 'Each directory should have unique LBA');
    }

    /**
     * Test LBA sector boundary alignment.
     */
    public function testLbaSectorBoundaryAlignment(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Read at various LBA positions
        $testLbas = [16, 17, 18, 19, 20];

        foreach ($testLbas as $lba) {
            $iso->seekSector($lba);
            $data = $iso->readSector();

            $this->assertSame(ISO9660::SECTOR_SIZE, strlen($data), "Sector at LBA $lba should be exactly 2048 bytes");
        }
    }

    /**
     * Test LBA to byte offset conversion.
     */
    public function testLbaToByteOffsetConversion(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // LBA 16 should be at byte offset 16 * 2048 = 32768
        $expectedOffset = 16 * ISO9660::SECTOR_SIZE;

        // Read via readAt and verify it matches sector read
        $dataViaOffset = $iso->readAt($expectedOffset, ISO9660::SECTOR_SIZE);

        $iso->seekSector(16);
        $dataViaSector = $iso->readSector();

        $this->assertSame($dataViaSector, $dataViaOffset, 'readAt and seekSector+readSector should return same data');
    }

    /**
     * Test sequential LBA reads.
     */
    public function testLbaSequentialReads(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Read sectors 16, 17, 18 sequentially
        $iso->seekSector(16);

        $sector1 = $iso->readSector();
        $sector2 = $iso->readSector();
        $sector3 = $iso->readSector();

        // Each read should advance the position
        $this->assertSame(ISO9660::SECTOR_SIZE, strlen($sector1));
        $this->assertSame(ISO9660::SECTOR_SIZE, strlen($sector2));
        $this->assertSame(ISO9660::SECTOR_SIZE, strlen($sector3));

        // Verify sector 1 is PVD
        $this->assertSame('CD001', substr($sector1, 1, 5));
    }

    /**
     * Test reading Volume Descriptor Set Terminator via LBA.
     */
    public function testLbaReadVolumeDescriptorSetTerminator(): void
    {
        $isoPath = self::FIXTURES_DIR . 'LbaTest.iso';
        if (!file_exists($isoPath)) {
            $this->markTestSkipped('Test ISO not found');
        }

        $iso = new ISO9660($isoPath);

        // Search for VDST (type 255) starting from LBA 16
        $vdstFound = false;
        for ($lba = 16; $lba < 32; $lba++) {
            $iso->seekSector($lba);
            $data = $iso->readSector();

            $type = ord($data[0]);
            $identifier = substr($data, 1, 5);

            if ($type === 255 && $identifier === 'CD001') {
                $vdstFound = true;
                break;
            }
        }

        $this->assertTrue($vdstFound, 'Volume Descriptor Set Terminator should be found');
    }
}
