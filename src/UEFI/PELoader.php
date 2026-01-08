<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Runtime\RuntimeInterface;

final class PELoader
{
    /**
     * @return array{base:int, entry:int, size:int, bits:int}
     */
    public function load(RuntimeInterface $runtime, string $image, ?int $loadBase = null): array
    {
        $info = $this->inspect($image);
        $imageBase = $info['imageBase'];
        $entryRva = $info['entryRva'];
        $sizeOfImage = $info['sizeOfImage'];
        $sizeOfHeaders = $info['sizeOfHeaders'];
        $bits = $info['bits'];
        $numSections = $info['numSections'];
        $optionalOff = $info['optionalOff'];
        $sizeOfOptional = $info['sizeOfOptional'];

        $targetBase = $loadBase ?? $imageBase;
        if ($targetBase <= 0) {
            $targetBase = 0x00400000;
        }
        $targetBase = $targetBase & ~0xFFF;
        $delta = $targetBase - $imageBase;

        $memory = $runtime->memory();
        $this->zeroRegion($memory, $targetBase, $sizeOfImage);

        $headers = substr($image, 0, $sizeOfHeaders);
        $memory->copyFromString($headers, $targetBase);

        $sectionOff = $optionalOff + $sizeOfOptional;
        for ($i = 0; $i < $numSections; $i++) {
            $off = $sectionOff + ($i * 40);
            if ($off + 40 > strlen($image)) {
                break;
            }

            $virtSize = $this->u32($image, $off + 8);
            $virtAddr = $this->u32($image, $off + 12);
            $rawSize = $this->u32($image, $off + 16);
            $rawPtr = $this->u32($image, $off + 20);

            if ($rawSize > 0 && $rawPtr + $rawSize <= strlen($image)) {
                $data = substr($image, $rawPtr, $rawSize);
                $memory->copyFromString($data, $targetBase + $virtAddr);
            }

            if ($virtSize > $rawSize) {
                $zeroLen = $virtSize - $rawSize;
                $this->zeroRegion($memory, $targetBase + $virtAddr + $rawSize, $zeroLen);
            }
        }

        if ($delta !== 0 && $info['relocRva'] > 0 && $info['relocSize'] > 0) {
            $this->applyRelocations(
                $runtime,
                $targetBase,
                $info['relocRva'],
                $info['relocSize'],
                $delta,
                $bits,
            );
        }

        return [
            'base' => $targetBase,
            'entry' => $targetBase + $entryRva,
            'size' => $sizeOfImage,
            'bits' => $bits,
        ];
    }

    /**
     * @return array{imageBase:int, entryRva:int, sizeOfImage:int, sizeOfHeaders:int, bits:int, magic:int, numSections:int, optionalOff:int, sizeOfOptional:int, relocRva:int, relocSize:int}
     */
    public function inspect(string $image): array
    {
        if (strlen($image) < 0x100) {
            throw new StreamReaderException('EFI image too small');
        }

        if (substr($image, 0, 2) !== 'MZ') {
            throw new StreamReaderException('EFI image missing MZ header');
        }

        $eLfAnew = $this->u32($image, 0x3C);
        if ($eLfAnew <= 0 || $eLfAnew + 4 > strlen($image)) {
            throw new StreamReaderException('EFI image invalid e_lfanew');
        }

        if (substr($image, $eLfAnew, 4) !== "PE\x00\x00") {
            throw new StreamReaderException('EFI image missing PE header');
        }

        $fileHeaderOff = $eLfAnew + 4;
        $numSections = $this->u16($image, $fileHeaderOff + 2);
        $sizeOfOptional = $this->u16($image, $fileHeaderOff + 16);
        $optionalOff = $fileHeaderOff + 20;

        $magic = $this->u16($image, $optionalOff);
        $entryRva = $this->u32($image, $optionalOff + 0x10);
        if ($magic === 0x20B) {
            $imageBase = $this->u64($image, $optionalOff + 0x18);
            $bits = 64;
        } elseif ($magic === 0x10B) {
            $imageBase = $this->u32($image, $optionalOff + 0x1C);
            $bits = 32;
        } else {
            throw new StreamReaderException('EFI image not PE32/PE32+');
        }

        $sizeOfImage = $this->u32($image, $optionalOff + 0x38);
        $sizeOfHeaders = $this->u32($image, $optionalOff + 0x3C);

        $relocRva = 0;
        $relocSize = 0;
        $dirOffset = $magic === 0x20B ? 0x70 : 0x60;
        if ($sizeOfOptional >= ($dirOffset + (6 * 8))) {
            $relocRva = $this->u32($image, $optionalOff + $dirOffset + (5 * 8));
            $relocSize = $this->u32($image, $optionalOff + $dirOffset + (5 * 8) + 4);
        }

        return [
            'imageBase' => $imageBase,
            'entryRva' => $entryRva,
            'sizeOfImage' => $sizeOfImage,
            'sizeOfHeaders' => $sizeOfHeaders,
            'bits' => $bits,
            'magic' => $magic,
            'numSections' => $numSections,
            'optionalOff' => $optionalOff,
            'sizeOfOptional' => $sizeOfOptional,
            'relocRva' => $relocRva,
            'relocSize' => $relocSize,
        ];
    }

    private function zeroRegion(object $memory, int $base, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        $chunk = str_repeat("\x00", min($size, 0x4000));
        $written = 0;
        while ($written < $size) {
            $len = min(strlen($chunk), $size - $written);
            $memory->copyFromString(substr($chunk, 0, $len), $base + $written);
            $written += $len;
        }
    }

    private function applyRelocations(
        RuntimeInterface $runtime,
        int $base,
        int $relocRva,
        int $relocSize,
        int $delta,
        int $bits,
    ): void {
        $ma = $runtime->memoryAccessor();
        $cursor = 0;
        while ($cursor + 8 <= $relocSize) {
            $blockBase = $base + $relocRva + $cursor;
            $pageRva = $ma->readPhysical32($blockBase);
            $blockSize = $ma->readPhysical32($blockBase + 4);
            if ($blockSize < 8) {
                break;
            }

            $entryCount = intdiv($blockSize - 8, 2);
            $entryBase = $blockBase + 8;
            for ($i = 0; $i < $entryCount; $i++) {
                $entry = $ma->readPhysical16($entryBase + ($i * 2));
                $type = ($entry >> 12) & 0xF;
                $offset = $entry & 0x0FFF;
                if ($type === 0) {
                    continue;
                }
                $addr = $base + $pageRva + $offset;
                if ($bits === 64 && $type === 10) {
                    $value = $ma->readPhysical64($addr);
                    $ma->writePhysical64($addr, $value + $delta);
                } elseif ($bits === 32 && $type === 3) {
                    $value = $ma->readPhysical32($addr);
                    $ma->writePhysical32($addr, ($value + $delta) & 0xFFFFFFFF);
                }
            }

            $cursor += $blockSize;
        }
    }

    private function u16(string $data, int $offset): int
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    private function u32(string $data, int $offset): int
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }

    private function u64(string $data, int $offset): int
    {
        $part = substr($data, $offset, 8);
        $values = unpack('V2', $part);
        return ($values[2] << 32) | $values[1];
    }
}
