<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\ISO\BootImage;
use PHPMachineEmulator\Stream\ISO\ISO9660;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentBlockIo;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentConsole;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentFileSystem;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentInterface;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentKernelFastBoot;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentServices;

class UEFIEnvironment implements UEFIEnvironmentInterface
{
    use UEFIEnvironmentConsole;
    use UEFIEnvironmentFileSystem;
    use UEFIEnvironmentBlockIo;
    use UEFIEnvironmentServices;
    use UEFIEnvironmentKernelFastBoot;

    private const EFI_SYSTEM_TABLE_SIGNATURE = 0x5453595320494249; // 'IBI SYST'
    private const EFI_BOOT_SERVICES_SIGNATURE = 0x56524553544F4F42; // 'BOOTSERV'
    private const EFI_RUNTIME_SERVICES_SIGNATURE = 0x56524553544E5552; // 'RUNTSERV'
    private const EFI_REVISION = 0x00020000;

    private const EFI_ERROR_BIT = -9223372036854775808; // 0x8000000000000000

    private const EFI_FILE_MODE_READ = 0x0000000000000001;
    private const EFI_FILE_DIRECTORY = 0x0000000000000010;

    private const EFI_MEMORY_TYPE_RESERVED = 0;
    private const EFI_MEMORY_TYPE_LOADER_CODE = 1;
    private const EFI_MEMORY_TYPE_LOADER_DATA = 2;
    private const EFI_MEMORY_TYPE_BOOT_SERVICES_CODE = 3;
    private const EFI_MEMORY_TYPE_BOOT_SERVICES_DATA = 4;
    private const EFI_MEMORY_TYPE_CONVENTIONAL = 7;

    private const GUID_LOADED_IMAGE = '5b1b31a1-9562-11d2-8e3f-00a0c969723b';
    private const GUID_SIMPLE_FS = '964e5b22-6459-11d2-8e39-00a0c969723b';
    private const GUID_BLOCK_IO = '964e5b21-6459-11d2-8e39-00a0c969723b';
    private const GUID_DISK_IO = 'ce345171-ba0b-11d2-8e4f-00a0c969723b';
    private const GUID_SIMPLE_TEXT_IN = '387477c1-69c7-11d2-8e39-00a0c969723b';
    private const GUID_SIMPLE_TEXT_IN_EX = 'dd9e7534-7762-4698-8c14-f58517a625aa';
    private const GUID_SIMPLE_TEXT_OUT = '387477c2-69c7-11d2-8e39-00a0c969723b';
    private const GUID_FILE_INFO = '09576e92-6d3f-11d2-8e39-00a0c969723b';
    private const GUID_FS_INFO = '09576e93-6d3f-11d2-8e39-00a0c969723b';
    private const GUID_DEVICE_PATH = '09576e91-6d3f-11d2-8e39-00a0c969723b';

    private const FILE_PROTOCOL_REVISION = 0x00010000;
    private const SIMPLE_FS_REVISION = 0x00010000;
    private const LOADED_IMAGE_REVISION = 0x00001000;



    private RuntimeInterface $runtime;
    private ISO9660 $iso;
    private ?BootImage $bootImage = null;
    private UEFIDispatcher $dispatcher;
    private UEFIMemory $mem;
    private UEFIAllocator $allocator;

    private int $imageBase;
    private int $imageSize;
    private string $imagePath;

    private int $imageHandle = 0;
    private int $deviceHandle = 0;
    private int $consoleInHandle = 0;
    private int $consoleOutHandle = 0;

    private int $systemTable = 0;
    private int $runtimeServices = 0;
    private int $bootServices = 0;
    private int $loadedImageProtocol = 0;
    private int $simpleFileSystem = 0;
    private int $blockIo = 0;
    private int $blockIoMedia = 0;
    private int $diskIo = 0;
    private int $blockIoMediaId = 1;
    private int $blockIoBlockSize = 0;
    private int $blockIoLastBlock = 0;

    private int $simpleTextIn = 0;
    private int $simpleTextInEx = 0;
    private int $simpleTextOut = 0;
    private int $simpleTextOutMode = 0;
    private int $waitForKeyEvent = 0;

    /** @var array<int, array{protocols: array<string, int>}> */
    private array $handles = [];

    /** @var array<string, int> */
    private array $protocolRegistry = [];

    /** @var array<int, array{path: string, isDir: bool, data: string, position: int, entries: array<int, array<string, mixed>>|null, entryIndex: int, kernelInfo: array<string, mixed>|null, kernelRegistered: bool}> */
    private array $fileHandles = [];

    /** @var array<int, array{signaled: bool}> */
    private array $events = [];

    /** @var array<int, array{entry: int, base: int, size: int, bits: int, path: string}> */
    private array $loadedImages = [];

    /** @var array<int, array{start: int, end: int, type: int}> */
    private array $pageAllocations = [];

    private int $pageAllocBase = 0x00100000;
    private int $pageAllocLimit = 0;

    private int $imageAllocCursor = 0;
    private int $imageAllocLimit = 0;

    private int $loadLogCount = 0;

    /** @var array<int, array<string, mixed>> */
    private array $linuxKernelImages = [];
    private int $linuxKernelFastBootLogCount = 0;
    private int $linuxKernelGdtLogCount = 0;
    private int $linuxKernelUnwindLogCount = 0;
    private bool $linuxKernelCandidateLoaded = false;
    private int $linuxKernelProbeLogCount = 0;
    private int $linuxKernelSkipLogCount = 0;
    private int $linuxKernelScanCooldown = 0;

    private int $loadedImageSystemTableOffset = 0;
    private int $mapKey = 1;
    private bool $bootServicesExited = false;

    private int $pointerSize;
    private int $pointerAlign;

    private int $textOutLogCount = 0;
    private int $fileLogCount = 0;
    private int $protocolLogCount = 0;
    private int $allocLogCount = 0;
    private int $memoryMapLogCount = 0;
    private int $blockIoLogCount = 0;
    private int $diskIoLogCount = 0;
    private int $locateHandleLogCount = 0;
    private int $exitBootServicesLogCount = 0;

    public function __construct(
        RuntimeInterface $runtime,
        ISO9660 $iso,
        int $imageBase,
        int $imageSize,
        string $imagePath,
        int $allocBase = 0x06000000,
        int $allocLimit = 0x0A000000,
        int $pointerSize = 8,
        ?BootImage $bootImage = null,
    ) {
        $this->runtime = $runtime;
        $this->iso = $iso;
        $this->imageBase = $imageBase;
        $this->imageSize = $imageSize;
        $this->imagePath = $imagePath;
        $this->bootImage = $bootImage;

        $this->mem = new UEFIMemory($runtime);
        $this->allocator = new UEFIAllocator($this->mem, $allocBase, $allocLimit);
        $dispatcherBase = $this->allocator->allocate(0x10000, 0x20);
        $this->dispatcher = new UEFIDispatcher($dispatcherBase, 0x20);
        $this->pointerSize = $pointerSize === 4 ? 4 : 8;
        $this->pointerAlign = $this->pointerSize;
        $imageEnd = $this->imageBase + $this->imageSize;
        $this->imageAllocCursor = $this->align(max($imageEnd, 0x00400000), 0x1000);
        $this->imageAllocLimit = $this->allocator->base();

        $memorySize = $runtime->logicBoard()->memory()->initialMemory();
        $this->pageAllocLimit = $memorySize;
    }

    public function dispatcher(): UEFIDispatcher
    {
        return $this->dispatcher;
    }

    public function imageHandle(): int
    {
        return $this->imageHandle;
    }

    public function systemTable(): int
    {
        return $this->systemTable;
    }

    public function allocator(): UEFIAllocator
    {
        return $this->allocator;
    }

    private function align(int $offset, int $align): int
    {
        return ($offset + ($align - 1)) & (~($align - 1));
    }

    private function fastKernelEnabled(): bool
    {
        $value = getenv('PHPME_FAST_KERNEL');
        if ($value === false) {
            return false;
        }
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === '0' || $value === 'false' || $value === 'off' || $value === 'no') {
            return false;
        }
        return true;
    }

    private function writePtr(int $address, int $value): void
    {
        if ($this->pointerSize === 8) {
            $this->mem->writeU64($address, $value);
            return;
        }
        $this->mem->writeU32($address, $value & 0xFFFFFFFF);
    }

    private function readPtr(int $address): int
    {
        if ($this->pointerSize === 8) {
            return $this->mem->readU64($address);
        }
        return $this->mem->readU32($address) & 0xFFFFFFFF;
    }

    private function writeUintN(int $address, int $value): void
    {
        $this->writePtr($address, $value);
    }

    private function readUintN(int $address): int
    {
        return $this->readPtr($address);
    }

    public function build(): int
    {
        $this->imageHandle = $this->allocateHandle();
        $this->deviceHandle = $this->allocateHandle();
        $this->consoleInHandle = $this->allocateHandle();
        $this->consoleOutHandle = $this->allocateHandle();

        $this->buildTextInput();
        $this->buildTextOutput();
        $this->buildSimpleFileSystem();
        $this->buildBlockIo();
        $this->buildLoadedImage();

        $this->runtimeServices = $this->buildRuntimeServices();
        $this->bootServices = $this->buildBootServices();
        $this->systemTable = $this->buildSystemTable();

        $this->writePtr($this->loadedImageProtocol + $this->loadedImageSystemTableOffset, $this->systemTable);

        return $this->systemTable;
    }

    public function allocateStack(int $size): int
    {
        $bytes = $this->align(max(1, $size), 0x1000);
        $addr = $this->findFreeRange($bytes, $this->pageAllocLimit - 1, 0x1000);
        if ($addr === null) {
            throw new \RuntimeException('UEFI stack allocator out of space');
        }
        $this->zeroMemory($addr, $bytes);
        $this->registerPageAllocation($addr, $bytes, self::EFI_MEMORY_TYPE_BOOT_SERVICES_DATA);
        return $addr;
    }

    private function allocateImageBase(int $size, int $align = 0x1000): int
    {
        $size = max(1, $size);
        $addr = $this->align($this->imageAllocCursor, $align);
        $next = $addr + $size;
        if ($addr == 0 || $next > $this->imageAllocLimit) {
            throw new \RuntimeException('UEFI image allocator out of space');
        }
        $this->imageAllocCursor = $next;
        return $addr;
    }

    private function zeroMemory(int $address, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $chunk = str_repeat("\x00", min($bytes, 0x4000));
        $offset = 0;
        $memory = $this->runtime->memory();

        while ($offset < $bytes) {
            $len = min(strlen($chunk), $bytes - $offset);
            $memory->copyFromString(substr($chunk, 0, $len), $address + $offset);
            $offset += $len;
        }
    }

    private function touchMemoryMap(): void
    {
        if ($this->bootServicesExited) {
            return;
        }

        if ($this->pointerSize === 4) {
            $this->mapKey = ($this->mapKey + 1) & 0xFFFFFFFF;
            if ($this->mapKey === 0) {
                $this->mapKey = 1;
            }
            return;
        }

        $this->mapKey++;
        if ($this->mapKey === 0) {
            $this->mapKey = 1;
        }
    }

    private function registerPageAllocation(int $address, int $bytes, int $type): void
    {
        $this->pageAllocations[] = [
            'start' => $address,
            'end' => $address + $bytes,
            'type' => $type,
        ];
        $this->touchMemoryMap();
    }

    private function unregisterPageAllocation(int $address, int $bytes): void
    {
        $end = $address + $bytes;
        foreach ($this->pageAllocations as $index => $alloc) {
            if ($alloc['start'] === $address && $alloc['end'] === $end) {
                unset($this->pageAllocations[$index]);
                $this->pageAllocations = array_values($this->pageAllocations);
                $this->touchMemoryMap();
                return;
            }
        }
    }

    /**
     * @return array<int, array{start:int, end:int}>
     */
    private function allocationReservedRanges(): array
    {
        $memorySize = $this->runtime->logicBoard()->memory()->initialMemory();
        $ranges = [];

        $ranges[] = [
            'start' => 0,
            'end' => min($this->pageAllocBase, $memorySize),
        ];

        $imageStart = $this->imageBase;
        $imageEnd = $this->imageBase + $this->imageSize;
        if ($imageEnd > $imageStart) {
            $ranges[] = [
                'start' => $imageStart,
                'end' => $imageEnd,
            ];
        }

        foreach ($this->loadedImages as $info) {
            $start = $info['base'] ?? 0;
            $end = $start + ($info['size'] ?? 0);
            if ($end > $start) {
                $ranges[] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        $allocStart = $this->allocator->base();
        $allocEnd = $this->allocator->cursor();
        if ($allocEnd > $allocStart) {
            $ranges[] = [
                'start' => $allocStart,
                'end' => $allocEnd,
            ];
        }

        foreach ($this->pageAllocations as $alloc) {
            $start = (int) ($alloc['start'] ?? 0);
            $end = (int) ($alloc['end'] ?? 0);
            if ($end > $start) {
                $ranges[] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        $ranges = array_values(array_filter($ranges, function (array $r) use ($memorySize): bool {
            return ($r['end'] > 0) && ($r['start'] < $memorySize) && ($r['end'] > $r['start']);
        }));

        return $this->mergeRanges($ranges);
    }

    /**
     * @param array<int, array{start:int, end:int}> $ranges
     * @return array<int, array{start:int, end:int}>
     */
    private function mergeRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        usort($ranges, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        $merged = [];
        foreach ($ranges as $range) {
            if ($merged === []) {
                $merged[] = $range;
                continue;
            }
            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];
            if ($range['start'] > $last['end']) {
                $merged[] = $range;
                continue;
            }
            $merged[$lastIndex]['end'] = max($last['end'], $range['end']);
        }

        return $merged;
    }

    /**
     * @param array<int, array{start:int, end:int}> $ranges
     */
    private function isRangeFree(int $address, int $bytes, array $ranges): bool
    {
        $end = $address + $bytes;
        foreach ($ranges as $range) {
            if ($address < $range['end'] && $end > $range['start']) {
                return false;
            }
        }
        return true;
    }

    private function findFreeRange(int $bytes, ?int $maxAddress, int $align = 0x1000): ?int
    {
        $bytes = max(1, $bytes);
        $align = max(1, $align);
        $base = $this->pageAllocBase;
        $limit = $this->pageAllocLimit;

        if ($maxAddress !== null) {
            $limit = min($limit, $maxAddress + 1);
        }

        if ($limit <= $base || $bytes > ($limit - $base)) {
            return null;
        }

        $ranges = $this->allocationReservedRanges();
        $gaps = [];
        $cursor = $base;

        foreach ($ranges as $range) {
            $start = max($range['start'], $base);
            $end = min($range['end'], $limit);
            if ($end <= $base || $start >= $limit) {
                continue;
            }
            if ($start > $cursor) {
                $gaps[] = ['start' => $cursor, 'end' => $start];
            }
            $cursor = max($cursor, $end);
            if ($cursor >= $limit) {
                break;
            }
        }

        if ($cursor < $limit) {
            $gaps[] = ['start' => $cursor, 'end' => $limit];
        }

        if ($maxAddress === null) {
            foreach ($gaps as $gap) {
                $addr = $this->align($gap['start'], $align);
                if ($addr + $bytes <= $gap['end']) {
                    return $addr;
                }
            }
            return null;
        }

        for ($i = count($gaps) - 1; $i >= 0; $i--) {
            $gap = $gaps[$i];
            $latest = $gap['end'] - $bytes;
            if ($latest < $gap['start']) {
                continue;
            }
            $addr = intdiv($latest, $align) * $align;
            if ($addr < $gap['start']) {
                continue;
            }
            return $addr;
        }

        return null;
    }
}
