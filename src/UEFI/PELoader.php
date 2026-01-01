<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Runtime\RuntimeInterface;

final class PELoader
{
    /**
     * @return array{base:int, entry:int, size:int}
     */
    public function load(RuntimeInterface $runtime, string $image): array
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
        if ($magic !== 0x20B) {
            throw new StreamReaderException('EFI image not PE32+');
        }

        $entryRva = $this->u32($image, $optionalOff + 0x10);
        $imageBase = $this->u64($image, $optionalOff + 0x18);
        $sizeOfImage = $this->u32($image, $optionalOff + 0x38);
        $sizeOfHeaders = $this->u32($image, $optionalOff + 0x3C);

        $memory = $runtime->memory();
        $headers = substr($image, 0, $sizeOfHeaders);
        $memory->copyFromString($headers, $imageBase);

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
                $memory->copyFromString($data, $imageBase + $virtAddr);
            }

            if ($virtSize > $rawSize) {
                $zeroLen = $virtSize - $rawSize;
                $chunk = str_repeat("\x00", min($zeroLen, 0x4000));
                $written = 0;
                while ($written < $zeroLen) {
                    $writeLen = min(strlen($chunk), $zeroLen - $written);
                    $memory->copyFromString(substr($chunk, 0, $writeLen), $imageBase + $virtAddr + $rawSize + $written);
                    $written += $writeLen;
                }
            }
        }

        return [
            'base' => $imageBase,
            'entry' => $imageBase + $entryRva,
            'size' => $sizeOfImage,
        ];
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
