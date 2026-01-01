<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\Media;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Exception\LogicBoardException;
use PHPMachineEmulator\Stream\ISO\ElTorito;

class MediaContext implements MediaContextInterface
{
    public const SECTOR_SIZE = 512;
    public const CD_SECTOR_SIZE = 2048;
    public const DEFAULT_HD_SECTORS_PER_TRACK = 63;
    public const DEFAULT_HD_HEADS_PER_CYLINDER = 16;
    public const DEFAULT_FLOPPY_CYLINDERS = 80;
    public const DEFAULT_FLOPPY_HEADS = 2;
    public const DEFAULT_FLOPPY_SECTORS_PER_TRACK = 18;
    public const DISKETTE_PARAM_SEG = 0xF000;
    public const DISKETTE_PARAM_OFF = 0xFE00;
    public const FIXED_DISK_PARAM_SEG = 0x9FC0;
    public const FIXED_DISK_PARAM_OFF = 0x0000;
    public const FIXED_DISK_PARAM_SIZE = 0x40;

    /**
     * @var array<int, MediaInfoInterface>
     */
    protected array $mediaDevices = [];

    public function __construct(
        protected MediaInfoInterface $primaryMedia,
    ) {
        $this->mediaDevices[0] = $primaryMedia;
    }

    public function primary(): MediaInfoInterface
    {
        return $this->primaryMedia;
    }

    public function bootDriveCounts(): array
    {
        $floppyCount = 0;
        $hardDriveCount = 0;

        foreach ($this->mediaDevices as $media) {
            switch ($media->driveType()) {
                case DriveType::FLOPPY:
                    $floppyCount++;
                    break;
                case DriveType::HARD_DISK:
                case DriveType::EXTERNAL:
                    $hardDriveCount++;
                    break;
                default:
                    break;
            }
        }

        if ($this->isElToritoFloppyEmulation()) {
            $floppyCount = max($floppyCount, 2);
        }

        return [$floppyCount, $hardDriveCount];
    }

    public function bootDriveNumber(): int
    {
        return match ($this->primaryDriveType()) {
            DriveType::FLOPPY => 0x00,
            DriveType::HARD_DISK,
            DriveType::EXTERNAL => 0x80,
            DriveType::CD_ROM,
            DriveType::CD_RAM => 0xE0,
        };
    }

    public function driveTypeForBiosNumber(int $dl): ?DriveType
    {
        [$floppyCount, $hardDriveCount] = $this->bootDriveCounts();
        $cdCount = $this->countCdDrives();

        if ($dl < 0x80) {
            return $dl < $floppyCount ? DriveType::FLOPPY : null;
        }

        if ($dl < 0xE0) {
            return ($dl - 0x80) < $hardDriveCount ? DriveType::HARD_DISK : null;
        }

        if ($dl >= 0xE0) {
            return ($dl - 0xE0) < $cdCount ? DriveType::CD_ROM : null;
        }

        return null;
    }

    public function primaryDriveType(): DriveType
    {
        return $this->primaryMedia->driveType();
    }

    public function hasDriveType(DriveType $driveType): bool
    {
        foreach ($this->mediaDevices as $media) {
            if ($media->driveType() === $driveType) {
                return true;
            }
        }
        return false;
    }

    public function isElToritoFloppyEmulation(): bool
    {
        $primary = $this->primaryMedia;
        if ($primary->bootType() !== BootType::EL_TORITO) {
            return false;
        }

        $stream = $primary->stream();
        if ($stream->isNoEmulation()) {
            return false;
        }

        $bootImage = $stream->bootImage();
        if ($bootImage === null) {
            return false;
        }

        return in_array($bootImage->mediaType(), [
            ElTorito::MEDIA_FLOPPY_1_2M,
            ElTorito::MEDIA_FLOPPY_1_44M,
            ElTorito::MEDIA_FLOPPY_2_88M,
        ], true);
    }

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    public function resolveFloppyGeometry(?int $mediaType, int $sizeBytes): array
    {
        $known = $this->floppyGeometryFromMediaType($mediaType);
        if ($known !== null) {
            return $known;
        }

        if ($sizeBytes > 0) {
            $fromSize = $this->floppyGeometryFromSize($sizeBytes);
            if ($fromSize !== null) {
                return $fromSize;
            }
        }

        return [
            self::DEFAULT_FLOPPY_CYLINDERS,
            self::DEFAULT_FLOPPY_HEADS,
            self::DEFAULT_FLOPPY_SECTORS_PER_TRACK,
        ];
    }

    /**
     * @return array{int,int,int} [cylinders, heads, sectors]
     */
    public function hardDiskGeometryFromSize(int $sizeBytes): array
    {
        if ($sizeBytes <= 0) {
            return [1024, self::DEFAULT_HD_HEADS_PER_CYLINDER, self::DEFAULT_HD_SECTORS_PER_TRACK];
        }

        $minGeometryBytes = self::SECTOR_SIZE
            * self::DEFAULT_HD_HEADS_PER_CYLINDER
            * self::DEFAULT_HD_SECTORS_PER_TRACK;
        if ($sizeBytes < $minGeometryBytes) {
            return [1024, self::DEFAULT_HD_HEADS_PER_CYLINDER, self::DEFAULT_HD_SECTORS_PER_TRACK];
        }

        $totalSectors = (int) max(1, intdiv($sizeBytes + self::SECTOR_SIZE - 1, self::SECTOR_SIZE));
        $heads = self::DEFAULT_HD_HEADS_PER_CYLINDER;
        $sectorsPerTrack = self::DEFAULT_HD_SECTORS_PER_TRACK;
        $cylinders = (int) max(1, intdiv($totalSectors + ($heads * $sectorsPerTrack - 1), $heads * $sectorsPerTrack));
        if ($cylinders > 1024) {
            $cylinders = 1024;
        }

        return [$cylinders, $heads, $sectorsPerTrack];
    }

    /**
     * @return array{int,int,int}|null
     */
    private function floppyGeometryFromMediaType(?int $mediaType): ?array
    {
        if ($mediaType === null) {
            return null;
        }

        return match ($mediaType) {
            ElTorito::MEDIA_FLOPPY_1_2M => [80, 2, 15],
            ElTorito::MEDIA_FLOPPY_1_44M => [80, 2, 18],
            ElTorito::MEDIA_FLOPPY_2_88M => [80, 2, 36],
            default => null,
        };
    }

    /**
     * @return array{int,int,int}|null
     */
    private function floppyGeometryFromSize(int $sizeBytes): ?array
    {
        $known = [
            368640 => [40, 2, 9],
            737280 => [80, 2, 9],
            1228800 => [80, 2, 15],
            1474560 => [80, 2, 18],
            2949120 => [80, 2, 36],
        ];
        if (isset($known[$sizeBytes])) {
            return $known[$sizeBytes];
        }

        $totalSectors = intdiv($sizeBytes + self::SECTOR_SIZE - 1, self::SECTOR_SIZE);
        if ($totalSectors <= 0) {
            return null;
        }

        $heads = self::DEFAULT_FLOPPY_HEADS;
        foreach ([36, 18, 15, 9] as $spt) {
            $perCylinder = $heads * $spt;
            if ($perCylinder <= 0) {
                continue;
            }
            if ($totalSectors % $perCylinder === 0) {
                $cylinders = intdiv($totalSectors, $perCylinder);
                if ($cylinders > 0 && $cylinders <= 1024) {
                    return [$cylinders, $heads, $spt];
                }
            }
        }

        return null;
    }

    private function countCdDrives(): int
    {
        $count = 0;
        foreach ($this->mediaDevices as $media) {
            if (in_array($media->driveType(), [DriveType::CD_ROM, DriveType::CD_RAM], true)) {
                $count++;
            }
        }
        return $count;
    }

    public function add(MediaInfoInterface $media, int $index): static
    {
        if ($index < 0) {
            throw new LogicBoardException('Media index must be non-negative');
        }

        $this->mediaDevices[$index] = $media;
        return $this;
    }

    public function get(int $index): MediaInfoInterface
    {
        if (!$this->has($index)) {
            throw new LogicBoardException("Media at index {$index} does not exist");
        }

        return $this->mediaDevices[$index];
    }

    public function has(int $index): bool
    {
        return isset($this->mediaDevices[$index]);
    }

    public function all(): array
    {
        return $this->mediaDevices;
    }
}
