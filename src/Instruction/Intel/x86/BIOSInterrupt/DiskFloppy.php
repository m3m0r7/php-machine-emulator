<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Media\DriveType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

trait DiskFloppy
{
    private function detectChange(RuntimeInterface $runtime): void
    {
        $dl = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        if (!$this->isValidBiosDrive($runtime, $dl)) {
            $this->fail($runtime, 0x01);
            return;
        }

        if ($this->isFloppyDrive($runtime, $dl)) {
            // Report "no change" to avoid DOS prompting for disk reinsertion.
            $this->succeed($runtime);
            return;
        }

        $this->fail($runtime, 0x01);
    }

    private function floppyDriveCount(RuntimeInterface $runtime): int
    {
        [$floppyCount] = $runtime->logicBoard()->media()->bootDriveCounts();
        return $floppyCount;
    }

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    private function getFloppyGeometry(RuntimeInterface $runtime): array
    {
        $bootStream = $runtime->logicBoard()->media()->primary()?->stream();
        $bootImage = $bootStream?->bootImage();
        $mediaType = null;

        if ($bootStream !== null && $bootImage !== null && !$bootStream->isNoEmulation()) {
            $mediaType = $bootImage->mediaType();
            $sizeBytes = $bootImage->size();
        } else {
            $sizeBytes = $bootStream?->backingFileSize() ?? 0;
        }

        return $runtime->logicBoard()->media()->resolveFloppyGeometry($mediaType, $sizeBytes);
    }

    private function isFloppyDrive(RuntimeInterface $runtime, int $dl): bool
    {
        return $runtime->logicBoard()->media()->driveTypeForBiosNumber($dl) === DriveType::FLOPPY;
    }

    private function isElToritoFloppyEmulation(RuntimeInterface $runtime): bool
    {
        return $runtime->logicBoard()->media()->isElToritoFloppyEmulation();
    }

    private function shouldMirrorFloppyDriveB(RuntimeInterface $runtime, int $dl): bool
    {
        return $dl === 1 && $runtime->logicBoard()->media()->isElToritoFloppyEmulation();
    }
}
