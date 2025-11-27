<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

class PrimaryVolumeDescriptor
{
    public readonly string $systemIdentifier;
    public readonly string $volumeIdentifier;
    public readonly int $volumeSpaceSize;
    public readonly int $volumeSetSize;
    public readonly int $volumeSequenceNumber;
    public readonly int $logicalBlockSize;
    public readonly int $pathTableSize;
    public readonly int $pathTableLocationL;
    public readonly int $pathTableLocationM;
    public readonly int $rootDirectoryLBA;
    public readonly int $rootDirectorySize;

    public function __construct(string $data)
    {
        // System Identifier (bytes 8-39)
        $this->systemIdentifier = trim(substr($data, 8, 32));

        // Volume Identifier (bytes 40-71)
        $this->volumeIdentifier = trim(substr($data, 40, 32));

        // Volume Space Size (bytes 80-87, both-endian)
        $this->volumeSpaceSize = $this->readBothEndian32($data, 80);

        // Volume Set Size (bytes 120-123, both-endian)
        $this->volumeSetSize = $this->readBothEndian16($data, 120);

        // Volume Sequence Number (bytes 124-127, both-endian)
        $this->volumeSequenceNumber = $this->readBothEndian16($data, 124);

        // Logical Block Size (bytes 128-131, both-endian)
        $this->logicalBlockSize = $this->readBothEndian16($data, 128);

        // Path Table Size (bytes 132-139, both-endian)
        $this->pathTableSize = $this->readBothEndian32($data, 132);

        // Location of Type L Path Table (bytes 140-143, little-endian)
        $this->pathTableLocationL = unpack('V', substr($data, 140, 4))[1];

        // Location of Type M Path Table (bytes 148-151, big-endian)
        $this->pathTableLocationM = unpack('N', substr($data, 148, 4))[1];

        // Root Directory Record (bytes 156-189)
        $rootDirRecord = substr($data, 156, 34);
        $this->rootDirectoryLBA = unpack('V', substr($rootDirRecord, 2, 4))[1];
        $this->rootDirectorySize = unpack('V', substr($rootDirRecord, 10, 4))[1];
    }

    private function readBothEndian16(string $data, int $offset): int
    {
        // Both-endian format: little-endian followed by big-endian
        // We read little-endian (first 2 bytes)
        return unpack('v', substr($data, $offset, 2))[1];
    }

    private function readBothEndian32(string $data, int $offset): int
    {
        // Both-endian format: little-endian followed by big-endian
        // We read little-endian (first 4 bytes)
        return unpack('V', substr($data, $offset, 4))[1];
    }
}
