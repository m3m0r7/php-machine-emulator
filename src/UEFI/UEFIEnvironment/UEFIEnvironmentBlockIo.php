<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\ISO\ISO9660;

trait UEFIEnvironmentBlockIo
{
    private function buildBlockIo(): void
    {
        $reset = $this->dispatcher->register('BlockIo.Reset', fn(RuntimeInterface $runtime) => $this->blockIoReset($runtime));
        $read = $this->dispatcher->register('BlockIo.ReadBlocks', fn(RuntimeInterface $runtime) => $this->blockIoReadBlocks($runtime));
        $write = $this->dispatcher->register('BlockIo.WriteBlocks', fn(RuntimeInterface $runtime) => $this->blockIoWriteBlocks($runtime));
        $flush = $this->dispatcher->register('BlockIo.FlushBlocks', fn(RuntimeInterface $runtime) => $this->blockIoFlushBlocks($runtime));
        $diskRead = $this->dispatcher->register('DiskIo.ReadDisk', fn(RuntimeInterface $runtime) => $this->diskIoReadDisk($runtime));
        $diskWrite = $this->dispatcher->register('DiskIo.WriteDisk', fn(RuntimeInterface $runtime) => $this->diskIoWriteDisk($runtime));

        $this->blockIoMediaId = 1;
        $this->blockIoBlockSize = ISO9660::SECTOR_SIZE;
        $volumeBytes = $this->iso->fileSize();
        $blocks = intdiv($volumeBytes + $this->blockIoBlockSize - 1, $this->blockIoBlockSize);
        $this->blockIoLastBlock = $blocks > 0 ? $blocks - 1 : 0;

        if ($this->pointerSize === 8) {
            $mediaSize = 32;
            $this->blockIoMedia = $this->allocator->allocateZeroed($mediaSize, 8);
            $this->mem->writeU32($this->blockIoMedia, $this->blockIoMediaId);
            $this->mem->writeU8($this->blockIoMedia + 4, 1);
            $this->mem->writeU8($this->blockIoMedia + 5, 1);
            $this->mem->writeU8($this->blockIoMedia + 6, 0);
            $this->mem->writeU8($this->blockIoMedia + 7, 1);
            $this->mem->writeU8($this->blockIoMedia + 8, 0);
            $this->mem->writeU32($this->blockIoMedia + 12, $this->blockIoBlockSize);
            $this->mem->writeU32($this->blockIoMedia + 16, 0);
            $this->mem->writeU64($this->blockIoMedia + 24, $this->blockIoLastBlock);
        } else {
            $mediaSize = 28;
            $this->blockIoMedia = $this->allocator->allocateZeroed($mediaSize, 4);
            $this->mem->writeU32($this->blockIoMedia, $this->blockIoMediaId);
            $this->mem->writeU8($this->blockIoMedia + 4, 1);
            $this->mem->writeU8($this->blockIoMedia + 5, 1);
            $this->mem->writeU8($this->blockIoMedia + 6, 0);
            $this->mem->writeU8($this->blockIoMedia + 7, 1);
            $this->mem->writeU8($this->blockIoMedia + 8, 0);
            $this->mem->writeU32($this->blockIoMedia + 12, $this->blockIoBlockSize);
            $this->mem->writeU32($this->blockIoMedia + 16, 0);
            $this->mem->writeU64($this->blockIoMedia + 20, $this->blockIoLastBlock);
        }

        $blockIoSize = 8 + ($this->pointerSize * 5);
        $this->blockIo = $this->allocator->allocateZeroed($blockIoSize, $this->pointerAlign);
        $this->mem->writeU64($this->blockIo, 0x00010000);
        $offset = $this->blockIo + 8;
        $this->writePtr($offset, $this->blockIoMedia);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $reset);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $read);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $write);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $flush);

        $diskIoSize = 8 + ($this->pointerSize * 2);
        $this->diskIo = $this->allocator->allocateZeroed($diskIoSize, $this->pointerAlign);
        $this->mem->writeU64($this->diskIo, 0x00010000);
        $offset = $this->diskIo + 8;
        $this->writePtr($offset, $diskRead);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $diskWrite);

        $this->registerHandleProtocol($this->deviceHandle, self::GUID_BLOCK_IO, $this->blockIo);
        $this->registerHandleProtocol($this->deviceHandle, self::GUID_DISK_IO, $this->diskIo);
        $devicePath = $this->buildFilePathDevicePath('\\');
        $this->registerHandleProtocol($this->deviceHandle, self::GUID_DEVICE_PATH, $devicePath);
        $this->protocolRegistry[self::GUID_BLOCK_IO] = $this->blockIo;
        $this->protocolRegistry[self::GUID_DISK_IO] = $this->diskIo;
    }

    private function blockIoReset(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function blockIoReadBlocks(RuntimeInterface $runtime): void
    {
        $mediaId = $this->arg($runtime, 1);
        if ($this->pointerSize === 4) {
            $lbaLow = $this->arg($runtime, 2);
            $lbaHigh = $this->arg($runtime, 3);
            $lba = (($lbaHigh & 0xFFFFFFFF) << 32) | ($lbaLow & 0xFFFFFFFF);
            $bufferSize = $this->arg($runtime, 4);
            $bufferPtr = $this->arg($runtime, 5);
        } else {
            $lba = $this->arg($runtime, 2);
            $bufferSize = $this->arg($runtime, 3);
            $bufferPtr = $this->arg($runtime, 4);
        }

        $blockSize = $this->blockIoBlockSize;
        if ($blockSize <= 0) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $size = $bufferSize;
        if ($size <= 0) {
            $this->returnStatus($runtime, 0);
            return;
        }
        if (($size % $blockSize) !== 0) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        if ($mediaId !== $this->blockIoMediaId && $mediaId !== 0) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        if ($this->blockIoLogCount < 10) {
            $runtime->option()->logger()->warning(sprintf(
                'BlockIo.ReadBlocks: lba=0x%X size=%d',
                $lba,
                $size,
            ));
            $this->blockIoLogCount++;
        }

        $blocks = intdiv($size, $blockSize);
        $end = $lba + $blocks - 1;
        if ($lba < 0 || $end > $this->blockIoLastBlock) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $offset = $lba * $blockSize;
        $data = $this->iso->readAt($offset, $size);
        if ($data === false) {
            $this->returnStatus($runtime, $this->efiError(7));
            return;
        }
        if (strlen($data) < $size) {
            $data .= str_repeat("\0", $size - strlen($data));
        }

        $cpu = $runtime->context()->cpu();
        if (!$cpu->isPagingEnabled()) {
            $memory = $runtime->memory();
            if ($memory->ensureCapacity($bufferPtr + $size)) {
                $memory->copyFromString($data, $bufferPtr);
                $this->returnStatus($runtime, 0);
                return;
            }
        }

        $cpu = $runtime->context()->cpu();
        if (!$cpu->isPagingEnabled()) {
            $memory = $runtime->memory();
            if ($memory->ensureCapacity($bufferPtr + $bufferSize)) {
                $memory->copyFromString($data, $bufferPtr);
                $this->returnStatus($runtime, 0);
                return;
            }
        }

        $this->mem->writeBytes($bufferPtr, $data);
        $this->returnStatus($runtime, 0);
    }

    private function blockIoWriteBlocks(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, $this->efiError(3));
    }

    private function blockIoFlushBlocks(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function diskIoReadDisk(RuntimeInterface $runtime): void
    {
        $mediaId = $this->arg($runtime, 1);
        if ($this->pointerSize === 4) {
            $offsetLow = $this->arg($runtime, 2);
            $offsetHigh = $this->arg($runtime, 3);
            $offset = (($offsetHigh & 0xFFFFFFFF) << 32) | ($offsetLow & 0xFFFFFFFF);
            $bufferSize = $this->arg($runtime, 4);
            $bufferPtr = $this->arg($runtime, 5);
        } else {
            $offset = $this->arg($runtime, 2);
            $bufferSize = $this->arg($runtime, 3);
            $bufferPtr = $this->arg($runtime, 4);
        }

        if ($mediaId !== $this->blockIoMediaId && $mediaId !== 0) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        if ($bufferSize <= 0) {
            $this->returnStatus($runtime, 0);
            return;
        }

        if ($this->diskIoLogCount < 10) {
            $runtime->option()->logger()->warning(sprintf(
                'DiskIo.ReadDisk: offset=0x%X size=%d',
                $offset,
                $bufferSize,
            ));
            $this->diskIoLogCount++;
        }

        $data = $this->iso->readAt($offset, $bufferSize);
        if ($data === false) {
            $this->returnStatus($runtime, $this->efiError(7));
            return;
        }
        if (strlen($data) < $bufferSize) {
            $data .= str_repeat("\0", $bufferSize - strlen($data));
        }

        $this->mem->writeBytes($bufferPtr, $data);
        $this->returnStatus($runtime, 0);
    }

    private function diskIoWriteDisk(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, $this->efiError(3));
    }
}
