<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Runtime\RuntimeInterface;

trait UEFIEnvironmentFileSystem
{
    private function buildSimpleFileSystem(): void
    {
        $openVolume = $this->dispatcher->register('SimpleFS.OpenVolume', fn(RuntimeInterface $runtime) => $this->simpleFsOpenVolume($runtime));

        $size = 8 + $this->pointerSize;
        $this->simpleFileSystem = $this->allocator->allocateZeroed($size, $this->pointerAlign);
        $this->mem->writeU64($this->simpleFileSystem, self::SIMPLE_FS_REVISION);
        $this->writePtr($this->simpleFileSystem + 8, $openVolume);

        $this->registerHandleProtocol($this->deviceHandle, self::GUID_SIMPLE_FS, $this->simpleFileSystem);
        $this->protocolRegistry[self::GUID_SIMPLE_FS] = $this->simpleFileSystem;
    }

    private function simpleFsOpenVolume(RuntimeInterface $runtime): void
    {
        if ($this->fileLogCount < 200) {
            $runtime->option()->logger()->warning('SIMPLE_FS: OpenVolume');
            $this->fileLogCount++;
        }
        $outPtr = $this->arg($runtime, 1);
        $root = $this->openFileHandle('/');
        $this->writePtr($outPtr, $root);
        $this->returnStatus($runtime, 0);
    }

    private function fileOpen(RuntimeInterface $runtime): void
    {
        $thisPtr = $this->arg($runtime, 0);
        $newHandlePtr = $this->arg($runtime, 1);
        $fileNamePtr = $this->arg($runtime, 2);
        $openMode = $this->arg($runtime, 3);

        $state = $this->fileHandles[$thisPtr] ?? null;
        if ($state === null) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        if (($openMode & self::EFI_FILE_MODE_READ) === 0) {
            $this->returnStatus($runtime, $this->efiError(3));
            return;
        }

        $fileName = $this->mem->readUtf16String($fileNamePtr);
        $basePath = $state['path'];
        if (!$state['isDir']) {
            $basePath = dirname($basePath);
        }

        $path = $this->resolvePath($basePath, $fileName);
        if ($this->fileLogCount < 200) {
            $runtime->option()->logger()->warning(sprintf('FILE_OPEN: %s', $path));
            $this->fileLogCount++;
        }
        $handle = $this->openFileHandle($path);
        if ($handle === 0) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->writePtr($newHandlePtr, $handle);
        $this->returnStatus($runtime, 0);
    }

    private function fileClose(RuntimeInterface $runtime): void
    {
        $thisPtr = $this->arg($runtime, 0);
        unset($this->fileHandles[$thisPtr]);
        $this->returnStatus($runtime, 0);
    }

    private function fileRead(RuntimeInterface $runtime): void
    {
        $thisPtr = $this->arg($runtime, 0);
        $sizePtr = $this->arg($runtime, 1);
        $buffer = $this->arg($runtime, 2);

        $state = $this->fileHandles[$thisPtr] ?? null;
        if ($state === null) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $requested = $this->readUintN($sizePtr);
        if (!$state['isDir'] && $requested <= 0) {
            $this->writeUintN($sizePtr, 0);
            $this->returnStatus($runtime, 0);
            return;
        }

        if (!$state['isDir'] && empty($state['kernelRegistered'])) {
            $kernelInfo = $state['kernelInfo'] ?? null;
            if (is_array($kernelInfo) && $buffer > 0) {
                $this->linuxKernelCandidateLoaded = true;
                $pos = (int) ($state['position'] ?? 0);
                if ($pos >= 0 && $buffer >= $pos) {
                    $base = $buffer - $pos;
                    if ($base > 0) {
                        $entry = $base + (int) ($kernelInfo['handover_offset'] ?? 0);
                        $this->registerLinuxKernelImage(
                            $runtime,
                            $thisPtr,
                            $base,
                            strlen($state['data']),
                            $state['path'],
                            $kernelInfo,
                            $entry,
                            'file',
                        );
                        $state['kernelRegistered'] = true;
                        $this->fileHandles[$thisPtr] = $state;
                    }
                }
            }
        }

        if ($state['isDir']) {
            $entries = $state['entries'] ?? [];
            $index = $state['entryIndex'] ?? 0;
            if ($index >= count($entries)) {
                $this->writeUintN($sizePtr, 0);
                $this->returnStatus($runtime, 0);
                return;
            }

            $entry = $entries[$index];
            $name = (string) ($entry['name'] ?? '');
            $nameBytes = $this->utf16Bytes($name . "\0");
            $infoSize = 80 + strlen($nameBytes);

            if ($requested < $infoSize) {
                $this->writeUintN($sizePtr, $infoSize);
                $this->returnStatus($runtime, $this->efiError(5));
                return;
            }

            $fileSize = (int) ($entry['size'] ?? 0);
            $this->mem->writeU64($buffer, $infoSize);
            $this->mem->writeU64($buffer + 8, $fileSize);
            $this->mem->writeU64($buffer + 16, $fileSize);
            $this->writeEfiTime($buffer + 24);
            $this->writeEfiTime($buffer + 40);
            $this->writeEfiTime($buffer + 56);
            $attr = ($entry['isDir'] ?? false) ? self::EFI_FILE_DIRECTORY : 0;
            $this->mem->writeU64($buffer + 72, $attr);
            $this->mem->writeBytes($buffer + 80, $nameBytes);

            $state['entryIndex'] = $index + 1;
            $state['position'] = $state['entryIndex'];
            $this->fileHandles[$thisPtr] = $state;

            $this->writeUintN($sizePtr, $infoSize);
            $this->returnStatus($runtime, 0);
            return;
        }

        $data = $state['data'];
        $pos = $state['position'];
        $chunk = substr($data, $pos, (int) $requested);
        $readLen = strlen($chunk);
        if ($readLen > 0) {
            $this->mem->writeBytes($buffer, $chunk);
        }
        $state['position'] = $pos + $readLen;
        $this->fileHandles[$thisPtr] = $state;

        $this->writeUintN($sizePtr, $readLen);
        $this->returnStatus($runtime, 0);
    }

    private function fileGetPosition(RuntimeInterface $runtime): void
    {
        $thisPtr = $this->arg($runtime, 0);
        $posPtr = $this->arg($runtime, 1);
        $state = $this->fileHandles[$thisPtr] ?? null;
        if ($state === null) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }
        $this->mem->writeU64($posPtr, $state['position']);
        $this->returnStatus($runtime, 0);
    }

    private function fileSetPosition(RuntimeInterface $runtime): void
    {
        $thisPtr = $this->arg($runtime, 0);
        $pos = $this->arg($runtime, 1);
        if ($this->pointerSize === 4) {
            $high = $this->arg($runtime, 2);
            $pos = (($high & 0xFFFFFFFF) << 32) | ($pos & 0xFFFFFFFF);
        }
        $state = $this->fileHandles[$thisPtr] ?? null;
        if ($state === null) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        if ($state['isDir']) {
            $entries = $state['entries'] ?? [];
            if ($pos === -1) {
                $state['entryIndex'] = count($entries);
            } else {
                $state['entryIndex'] = max(0, (int) $pos);
            }
            $state['position'] = $state['entryIndex'];
            $this->fileHandles[$thisPtr] = $state;
            $this->returnStatus($runtime, 0);
            return;
        }

        if ($pos === -1) {
            $pos = strlen($state['data']);
        }

        $state['position'] = max(0, (int) $pos);
        $this->fileHandles[$thisPtr] = $state;
        $this->returnStatus($runtime, 0);
    }

    private function fileGetInfo(RuntimeInterface $runtime): void
    {
        $thisPtr = $this->arg($runtime, 0);
        $infoTypePtr = $this->arg($runtime, 1);
        $sizePtr = $this->arg($runtime, 2);
        $buffer = $this->arg($runtime, 3);

        $state = $this->fileHandles[$thisPtr] ?? null;
        if ($state === null) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $requested = $this->readUintN($sizePtr);
        $guid = strtolower($this->mem->readGuid($infoTypePtr));
        if ($guid === self::GUID_FILE_INFO) {
            $fileName = $state['path'] === '/' ? '.' : basename($state['path']);
            $nameBytes = $this->utf16Bytes($fileName . "\0");
            $infoSize = 80 + strlen($nameBytes);
            if ($requested < $infoSize) {
                $this->writeUintN($sizePtr, $infoSize);
                $this->returnStatus($runtime, $this->efiError(5));
                return;
            }

            $this->mem->writeU64($buffer, $infoSize);
            $this->mem->writeU64($buffer + 8, strlen($state['data']));
            $this->mem->writeU64($buffer + 16, strlen($state['data']));
            $this->writeEfiTime($buffer + 24);
            $this->writeEfiTime($buffer + 40);
            $this->writeEfiTime($buffer + 56);
            $attr = $state['isDir'] ? self::EFI_FILE_DIRECTORY : 0;
            $this->mem->writeU64($buffer + 72, $attr);
            $this->mem->writeBytes($buffer + 80, $nameBytes);

            $this->writeUintN($sizePtr, $infoSize);
            $this->returnStatus($runtime, 0);
            return;
        }

        if ($guid === self::GUID_FS_INFO) {
            $volumeId = $this->iso->primaryDescriptor()?->volumeIdentifier ?? 'ISO';
            if ($volumeId === '') {
                $volumeId = 'ISO';
            }
            $label = $this->utf16Bytes($volumeId . "\0");
            $infoSize = 32 + strlen($label);
            if ($requested < $infoSize) {
                $this->writeUintN($sizePtr, $infoSize);
                $this->returnStatus($runtime, $this->efiError(5));
                return;
            }

            $volumeSize = $this->iso->fileSize();
            $this->mem->writeU64($buffer, $infoSize);
            $this->mem->writeU8($buffer + 8, 1);
            $this->mem->writeU64($buffer + 16, $volumeSize);
            $this->mem->writeU64($buffer + 24, 0);
            $this->mem->writeU32($buffer + 32, 2048);
            $this->mem->writeBytes($buffer + 36, $label);

            $this->writeUintN($sizePtr, $infoSize);
            $this->returnStatus($runtime, 0);
            return;
        }

        $this->returnStatus($runtime, $this->efiError(3));
    }

    private function fileSetInfo(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, $this->efiError(3));
    }

    private function fileWrite(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, $this->efiError(3));
    }

    private function fileDelete(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, $this->efiError(3));
    }

    private function fileFlush(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function readFileFromMedia(string $path): ?string
    {
        $data = $this->iso->readFile($path);
        if ($data !== null) {
            return $data;
        }
        if ($this->bootImage !== null) {
            return $this->bootImage->readFileByPath($path);
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readDirectoryFromMedia(string $path): ?array
    {
        $isoEntries = $this->iso->readDirectory($path);
        $bootEntries = null;
        if ($this->bootImage !== null) {
            $bootEntries = $this->bootImage->readDirectory($path);
        }

        if ($isoEntries === null && $bootEntries === null) {
            return null;
        }
        if ($isoEntries === null) {
            return $bootEntries;
        }
        if ($bootEntries === null) {
            return $isoEntries;
        }

        return $this->mergeDirectoryEntries($isoEntries, $bootEntries);
    }

    /**
     * @param array<int, array<string, mixed>> $primary
     * @param array<int, array<string, mixed>> $secondary
     * @return array<int, array<string, mixed>>
     */
    private function mergeDirectoryEntries(array $primary, array $secondary): array
    {
        $map = [];
        foreach ($primary as $entry) {
            $name = strtoupper((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $map[$name] = $entry;
            }
        }
        foreach ($secondary as $entry) {
            $name = strtoupper((string) ($entry['name'] ?? ''));
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = $entry;
            }
        }

        return array_values($map);
    }

    private function openFileHandle(string $path): int
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            $normalized = '/';
        }

        if ($normalized === '/') {
            $entries = $this->readDirectoryFromMedia('/') ?? [];
            return $this->createFileHandle($normalized, true, '', $entries);
        }

        $data = $this->readFileFromMedia($normalized);
        if ($data !== null) {
            return $this->createFileHandle($normalized, false, $data, null);
        }

        $entries = $this->readDirectoryFromMedia($normalized);
        if ($entries !== null) {
            return $this->createFileHandle($normalized, true, '', $entries);
        }

        return 0;
    }

    private function createFileHandle(string $path, bool $isDir, string $data, ?array $entries): int
    {
        $open = $this->dispatcher->register('File.Open', fn(RuntimeInterface $runtime) => $this->fileOpen($runtime));
        $close = $this->dispatcher->register('File.Close', fn(RuntimeInterface $runtime) => $this->fileClose($runtime));
        $delete = $this->dispatcher->register('File.Delete', fn(RuntimeInterface $runtime) => $this->fileDelete($runtime));
        $read = $this->dispatcher->register('File.Read', fn(RuntimeInterface $runtime) => $this->fileRead($runtime));
        $write = $this->dispatcher->register('File.Write', fn(RuntimeInterface $runtime) => $this->fileWrite($runtime));
        $getPos = $this->dispatcher->register('File.GetPosition', fn(RuntimeInterface $runtime) => $this->fileGetPosition($runtime));
        $setPos = $this->dispatcher->register('File.SetPosition', fn(RuntimeInterface $runtime) => $this->fileSetPosition($runtime));
        $getInfo = $this->dispatcher->register('File.GetInfo', fn(RuntimeInterface $runtime) => $this->fileGetInfo($runtime));
        $setInfo = $this->dispatcher->register('File.SetInfo', fn(RuntimeInterface $runtime) => $this->fileSetInfo($runtime));
        $flush = $this->dispatcher->register('File.Flush', fn(RuntimeInterface $runtime) => $this->fileFlush($runtime));

        $size = 8 + ($this->pointerSize * 10);
        $handle = $this->allocator->allocateZeroed($size, $this->pointerAlign);
        $this->mem->writeU64($handle, self::FILE_PROTOCOL_REVISION);
        $offset = $handle + 8;
        $this->writePtr($offset, $open);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $close);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $delete);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $read);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $write);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $getPos);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $setPos);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $getInfo);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $setInfo);
        $offset += $this->pointerSize;
        $this->writePtr($offset, $flush);

        $kernelInfo = null;
        if (!$isDir && $data !== '' && $this->isLikelyLinuxKernelPath($path)) {
            $kernelInfo = $this->parseLinuxKernelImage($data);
        }

        $this->fileHandles[$handle] = [
            'path' => $path,
            'isDir' => $isDir,
            'data' => $data,
            'position' => 0,
            'entries' => $entries,
            'entryIndex' => 0,
            'kernelInfo' => $kernelInfo,
            'kernelRegistered' => false,
        ];

        return $handle;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $parts = explode('/', $path);
        $clean = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($clean);
                continue;
            }
            $clean[] = $part;
        }

        return '/' . implode('/', $clean);
    }

    private function resolvePath(string $base, string $child): string
    {
        if ($child === '' || $child === '.') {
            return $this->normalizePath($base);
        }
        if ($child[0] === '/' || $child[0] === '\\') {
            return $this->normalizePath($child);
        }

        $base = rtrim($base, '/');
        return $this->normalizePath($base . '/' . $child);
    }

    private function devicePathToPath(int $devicePathPtr): ?string
    {
        if ($devicePathPtr === 0) {
            return null;
        }

        $offset = $devicePathPtr;
        $path = '';
        $found = false;

        for ($i = 0; $i < 64; $i++) {
            $type = $this->mem->readU8($offset);
            $subtype = $this->mem->readU8($offset + 1);
            if ($type === 0x7F && $subtype === 0xFF) {
                break;
            }

            $len = $this->mem->readU16($offset + 2);
            if ($len < 4) {
                break;
            }

            if ($type === 0x04 && $subtype === 0x04) {
                $nodePath = $this->mem->readUtf16String($offset + 4, $len - 4);
                if ($nodePath !== '') {
                    $path .= $nodePath;
                    $found = true;
                }
            }

            $offset += $len;
        }

        if (!$found) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return $path;
    }

    private function buildFilePathDevicePath(string $path): int
    {
        $path = $path === '' ? '\\EFI\\BOOT\\BOOTX64.EFI' : $path;
        $path = str_replace('/', '\\', $path);
        if ($path[0] !== '\\') {
            $path = '\\' . $path;
        }

        $utf16 = $this->utf16Bytes($path . "\0");
        $nodeLen = 4 + strlen($utf16);
        $total = $nodeLen + 4;
        $addr = $this->allocator->allocateZeroed($total, 4);

        $this->mem->writeU8($addr, 0x04);
        $this->mem->writeU8($addr + 1, 0x04);
        $this->mem->writeU16($addr + 2, $nodeLen);
        $this->mem->writeBytes($addr + 4, $utf16);

        $end = $addr + $nodeLen;
        $this->mem->writeU8($end, 0x7F);
        $this->mem->writeU8($end + 1, 0xFF);
        $this->mem->writeU16($end + 2, 4);

        return $addr;
    }

    private function utf16Bytes(string $value): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $out .= pack('v', ord($value[$i]));
        }
        return $out;
    }
}
