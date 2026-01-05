<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\UEFI\KeyboardContextInterface;
use PHPMachineEmulator\UEFI\PELoader;

trait UEFIEnvironmentServices
{
    private function buildLoadedImage(): void
    {
        $result = $this->createLoadedImageProtocol(
            $this->imageHandle,
            $this->imageBase,
            $this->imageSize,
            $this->imagePath,
            0,
            null,
        );

        $this->loadedImageProtocol = $result['addr'];
        $this->loadedImageSystemTableOffset = $result['systemTableOffset'];
        $this->protocolRegistry[self::GUID_LOADED_IMAGE] = $this->loadedImageProtocol;
    }

    /**
     * @return array{addr:int, systemTableOffset:int}
     */
    private function createLoadedImageProtocol(
        int $handle,
        int $base,
        int $size,
        string $path,
        int $parentHandle,
        ?int $systemTable,
    ): array {
        $unload = $this->dispatcher->register('LoadedImage.Unload', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $filePath = $this->buildFilePathDevicePath($path);
        $align64 = $this->pointerSize === 8 ? 8 : $this->pointerAlign;

        $offset = 0;
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);
        $offset += $this->pointerSize * 5;
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);
        $offset += $this->pointerSize * 2;
        $offset = $this->align($offset, $align64);
        $offset += 8;
        $offset += 4;
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);
        $offset += $this->pointerSize;
        $sizeTotal = $this->align($offset, $this->pointerAlign);

        $protocol = $this->allocator->allocateZeroed($sizeTotal, $this->pointerAlign);

        $offset = 0;
        $this->mem->writeU32($protocol + $offset, self::LOADED_IMAGE_REVISION);
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);

        $this->writePtr($protocol + $offset, $parentHandle);
        $offset += $this->pointerSize;
        $systemTableOffset = $offset;
        $this->writePtr($protocol + $offset, $systemTable ?? 0);
        $offset += $this->pointerSize;
        $this->writePtr($protocol + $offset, $this->deviceHandle);
        $offset += $this->pointerSize;
        $this->writePtr($protocol + $offset, $filePath);
        $offset += $this->pointerSize;
        $this->writePtr($protocol + $offset, 0);
        $offset += $this->pointerSize;

        $this->mem->writeU32($protocol + $offset, 0);
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);

        $this->writePtr($protocol + $offset, 0);
        $offset += $this->pointerSize;
        $this->writePtr($protocol + $offset, $base);
        $offset += $this->pointerSize;
        $offset = $this->align($offset, $align64);
        $this->mem->writeU64($protocol + $offset, $size);
        $offset += 8;
        $this->mem->writeU32($protocol + $offset, 1);
        $offset += 4;
        $this->mem->writeU32($protocol + $offset, 2);
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);
        $this->writePtr($protocol + $offset, $unload);

        $this->registerHandleProtocol($handle, self::GUID_LOADED_IMAGE, $protocol);
        $this->registerHandleProtocol($handle, self::GUID_DEVICE_PATH, $filePath);

        return [
            'addr' => $protocol,
            'systemTableOffset' => $systemTableOffset,
        ];
    }

    private function buildSystemTable(): int
    {
        $vendor = $this->allocator->allocateZeroed(64, 2);
        $this->mem->writeUtf16String($vendor, 'PHPME');

        $offset = 24 + $this->pointerSize + 4;
        $offset = $this->align($offset, $this->pointerAlign);
        $offset += $this->pointerSize * 10;
        $size = $this->align($offset, $this->pointerAlign);

        $addr = $this->allocator->allocateZeroed($size, $this->pointerAlign);
        $this->writeTableHeader($addr, self::EFI_SYSTEM_TABLE_SIGNATURE, self::EFI_REVISION, $size);

        $offset = 24;
        $this->writePtr($addr + $offset, $vendor);
        $offset += $this->pointerSize;
        $this->mem->writeU32($addr + $offset, 0x00010000);
        $offset += 4;
        $offset = $this->align($offset, $this->pointerAlign);
        $this->writePtr($addr + $offset, $this->consoleInHandle);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->simpleTextIn);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->consoleOutHandle);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->simpleTextOut);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->consoleOutHandle);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->simpleTextOut);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->runtimeServices);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, $this->bootServices);
        $offset += $this->pointerSize;
        $this->writeUintN($addr + $offset, 0);
        $offset += $this->pointerSize;
        $this->writePtr($addr + $offset, 0);

        $this->updateTableCrc($addr, $size);

        return $addr;
    }

    private function buildBootServices(): int
    {
        $pointers = [];
        $pointers[] = $this->dispatcher->register('BS.RaiseTPL', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.RestoreTPL', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.AllocatePages', fn(RuntimeInterface $runtime) => $this->bsAllocatePages($runtime));
        $pointers[] = $this->dispatcher->register('BS.FreePages', fn(RuntimeInterface $runtime) => $this->bsFreePages($runtime));
        $pointers[] = $this->dispatcher->register('BS.GetMemoryMap', fn(RuntimeInterface $runtime) => $this->bsGetMemoryMap($runtime));
        $pointers[] = $this->dispatcher->register('BS.AllocatePool', fn(RuntimeInterface $runtime) => $this->bsAllocatePool($runtime));
        $pointers[] = $this->dispatcher->register('BS.FreePool', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.CreateEvent', fn(RuntimeInterface $runtime) => $this->bsCreateEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.SetTimer', fn(RuntimeInterface $runtime) => $this->bsSetTimer($runtime));
        $pointers[] = $this->dispatcher->register('BS.WaitForEvent', fn(RuntimeInterface $runtime) => $this->bsWaitForEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.SignalEvent', fn(RuntimeInterface $runtime) => $this->bsSignalEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.CloseEvent', fn(RuntimeInterface $runtime) => $this->bsCloseEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.CheckEvent', fn(RuntimeInterface $runtime) => $this->bsCheckEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.InstallProtocolInterface', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.ReinstallProtocolInterface', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.UninstallProtocolInterface', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.HandleProtocol', fn(RuntimeInterface $runtime) => $this->bsHandleProtocol($runtime));
        $pointers[] = 0;
        $pointers[] = $this->dispatcher->register('BS.RegisterProtocolNotify', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.LocateHandle', fn(RuntimeInterface $runtime) => $this->bsLocateHandle($runtime));
        $pointers[] = $this->dispatcher->register('BS.LocateDevicePath', fn(RuntimeInterface $runtime) => $this->bsLocateDevicePath($runtime));
        $pointers[] = $this->dispatcher->register('BS.InstallConfigurationTable', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.LoadImage', fn(RuntimeInterface $runtime) => $this->bsLoadImage($runtime));
        $pointers[] = $this->dispatcher->register('BS.StartImage', fn(RuntimeInterface $runtime) => $this->bsStartImage($runtime));
        $pointers[] = $this->dispatcher->register('BS.Exit', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.UnloadImage', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.ExitBootServices', fn(RuntimeInterface $runtime) => $this->bsExitBootServices($runtime));
        $pointers[] = $this->dispatcher->register('BS.GetNextMonotonicCount', fn(RuntimeInterface $runtime) => $this->bsGetNextMonotonicCount($runtime));
        $pointers[] = $this->dispatcher->register('BS.Stall', fn(RuntimeInterface $runtime) => $this->bsStall($runtime));
        $pointers[] = $this->dispatcher->register('BS.SetWatchdogTimer', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.ConnectController', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.DisconnectController', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.OpenProtocol', fn(RuntimeInterface $runtime) => $this->bsOpenProtocol($runtime));
        $pointers[] = $this->dispatcher->register('BS.CloseProtocol', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.OpenProtocolInformation', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.ProtocolsPerHandle', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.LocateHandleBuffer', fn(RuntimeInterface $runtime) => $this->bsLocateHandleBuffer($runtime));
        $pointers[] = $this->dispatcher->register('BS.LocateProtocol', fn(RuntimeInterface $runtime) => $this->bsLocateProtocol($runtime));
        $pointers[] = $this->dispatcher->register('BS.InstallMultipleProtocolInterfaces', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.UninstallMultipleProtocolInterfaces', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.CalculateCrc32', fn(RuntimeInterface $runtime) => $this->bsCalculateCrc32($runtime));
        $pointers[] = $this->dispatcher->register('BS.CopyMem', fn(RuntimeInterface $runtime) => $this->bsCopyMem($runtime));
        $pointers[] = $this->dispatcher->register('BS.SetMem', fn(RuntimeInterface $runtime) => $this->bsSetMem($runtime));
        $pointers[] = $this->dispatcher->register('BS.CreateEventEx', fn(RuntimeInterface $runtime) => $this->bsCreateEvent($runtime));

        $size = 24 + (count($pointers) * $this->pointerSize);
        $addr = $this->allocator->allocateZeroed($size, $this->pointerAlign);
        $this->writeTableHeader($addr, self::EFI_BOOT_SERVICES_SIGNATURE, self::EFI_REVISION, $size);

        $offset = $addr + 24;
        foreach ($pointers as $ptr) {
            $this->writePtr($offset, $ptr);
            $offset += $this->pointerSize;
        }

        $this->updateTableCrc($addr, $size);

        return $addr;
    }

    private function buildRuntimeServices(): int
    {
        $pointers = [];
        $pointers[] = $this->dispatcher->register('RS.GetTime', fn(RuntimeInterface $runtime) => $this->rsGetTime($runtime));
        $pointers[] = $this->dispatcher->register('RS.SetTime', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.GetWakeupTime', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.SetWakeupTime', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.SetVirtualAddressMap', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('RS.ConvertPointer', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('RS.GetVariable', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.GetNextVariableName', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.SetVariable', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.GetNextHighMonotonicCount', fn(RuntimeInterface $runtime) => $this->bsGetNextMonotonicCount($runtime));
        $pointers[] = $this->dispatcher->register('RS.ResetSystem', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('RS.UpdateCapsule', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.QueryCapsuleCapabilities', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('RS.QueryVariableInfo', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));

        $size = 24 + (count($pointers) * $this->pointerSize);
        $addr = $this->allocator->allocateZeroed($size, $this->pointerAlign);
        $this->writeTableHeader($addr, self::EFI_RUNTIME_SERVICES_SIGNATURE, self::EFI_REVISION, $size);

        $offset = $addr + 24;
        foreach ($pointers as $ptr) {
            $this->writePtr($offset, $ptr);
            $offset += $this->pointerSize;
        }

        $this->updateTableCrc($addr, $size);

        return $addr;
    }

    private function writeTableHeader(int $address, int $signature, int $revision, int $headerSize): void
    {
        $this->mem->writeU64($address, $signature);
        $this->mem->writeU32($address + 8, $revision);
        $this->mem->writeU32($address + 12, $headerSize);
        $this->mem->writeU32($address + 16, 0);
        $this->mem->writeU32($address + 20, 0);
    }

    private function updateTableCrc(int $address, int $size): void
    {
        $this->mem->writeU32($address + 16, 0);
        $data = $this->mem->readBytes($address, $size);
        $crc = $this->crc32($data);
        $this->mem->writeU32($address + 16, $crc);
    }

    private function crc32(string $data): int
    {
        $hex = hash('crc32b', $data);
        return (int) hexdec($hex);
    }

    private function allocateHandle(): int
    {
        $handle = $this->allocator->allocateZeroed($this->pointerSize, $this->pointerAlign);
        $this->handles[$handle] = ['protocols' => []];
        return $handle;
    }

    private function registerHandleProtocol(int $handle, string $guid, int $interface): void
    {
        if (!isset($this->handles[$handle])) {
            $this->handles[$handle] = ['protocols' => []];
        }
        $this->handles[$handle]['protocols'][strtolower($guid)] = $interface;
    }

    private function handleProtocolInterface(int $handle, string $guid): ?int
    {
        $guid = strtolower($guid);
        return $this->handles[$handle]['protocols'][$guid] ?? null;
    }

    private function arg(RuntimeInterface $runtime, int $index): int
    {
        $ma = $runtime->memoryAccessor();
        if ($this->pointerSize === 8) {
            return match ($index) {
                0 => $ma->fetch(RegisterType::ECX)->asBytesBySize(64),
                1 => $ma->fetch(RegisterType::EDX)->asBytesBySize(64),
                2 => $ma->fetch(RegisterType::R8)->asBytesBySize(64),
                3 => $ma->fetch(RegisterType::R9)->asBytesBySize(64),
                default => $this->readPtr($this->stackArgAddress($runtime, $index)),
            };
        }

        return $this->readPtr($this->stackArgAddress($runtime, $index));
    }

    private function stackArgAddress(RuntimeInterface $runtime, int $index): int
    {
        if ($this->pointerSize === 8) {
            $rsp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(64);
            return $rsp + 0x28 + (($index - 4) * 8);
        }

        $esp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32);
        return $esp + 4 + ($index * 4);
    }

    private function returnStatus(RuntimeInterface $runtime, int $status): void
    {
        $size = $this->pointerSize === 8 ? 64 : 32;
        $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $status, $size);
    }

    private function efiError(int $code): int
    {
        $bit = $this->pointerSize === 8 ? self::EFI_ERROR_BIT : 0x80000000;
        return $bit | ($code & 0xFFFF);
    }

    private function writeEfiTime(int $address): void
    {
        $ts = time();
        $year = (int) gmdate('Y', $ts);
        $month = (int) gmdate('n', $ts);
        $day = (int) gmdate('j', $ts);
        $hour = (int) gmdate('G', $ts);
        $minute = (int) gmdate('i', $ts);
        $second = (int) gmdate('s', $ts);

        $this->mem->writeU16($address, $year);
        $this->mem->writeU8($address + 2, $month);
        $this->mem->writeU8($address + 3, $day);
        $this->mem->writeU8($address + 4, $hour);
        $this->mem->writeU8($address + 5, $minute);
        $this->mem->writeU8($address + 6, $second);
        $this->mem->writeU8($address + 7, 0);
        $this->mem->writeU32($address + 8, 0);
        $this->mem->writeU16($address + 12, 0);
        $this->mem->writeU8($address + 14, 0);
        $this->mem->writeU8($address + 15, 0);
    }

    private function bsAllocatePages(RuntimeInterface $runtime): void
    {
        $allocType = $this->arg($runtime, 0);
        $memType = $this->arg($runtime, 1);
        $pages = $this->arg($runtime, 2);
        $outPtr = $this->arg($runtime, 3);
        $bytes = (int) $pages * 4096;
        $requested = $this->mem->readU64($outPtr);

        if ($this->allocLogCount < 100) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.AllocatePages: type=%d mem=%d pages=%d req=0x%016X',
                $allocType,
                $memType,
                $pages,
                $requested,
            ));
            $this->allocLogCount++;
        }

        if ($bytes <= 0) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $addr = null;
        $align = 4096;

        if ($allocType === 2) {
            if (($requested & ($align - 1)) !== 0) {
                $this->returnStatus($runtime, $this->efiError(2));
                return;
            }
            $ranges = $this->allocationReservedRanges();
            if (!$this->isRangeFree($requested, $bytes, $ranges)) {
                $this->returnStatus($runtime, $this->efiError(9));
                return;
            }
            $addr = $requested;
        } elseif ($allocType === 1) {
            $addr = $this->findFreeRange($bytes, $requested, $align);
        } else {
            $addr = $this->findFreeRange($bytes, null, $align);
        }

        if ($addr === null || $addr <= 0) {
            $this->returnStatus($runtime, $this->efiError(9));
            return;
        }

        $this->zeroMemory($addr, $bytes);
        $this->mem->writeU64($outPtr, $addr);
        $type = $memType > 0 ? $memType : self::EFI_MEMORY_TYPE_BOOT_SERVICES_DATA;
        $this->registerPageAllocation($addr, $bytes, $type);
        $this->returnStatus($runtime, 0);
    }

    private function bsFreePages(RuntimeInterface $runtime): void
    {
        $address = $this->arg($runtime, 0);
        $pages = $this->arg($runtime, 1);
        $bytes = (int) $pages * 4096;
        if ($bytes > 0) {
            $this->unregisterPageAllocation($address, $bytes);
        }
        $this->returnStatus($runtime, 0);
    }

    private function bsAllocatePool(RuntimeInterface $runtime): void
    {
        $size = $this->arg($runtime, 1);
        $outPtr = $this->arg($runtime, 2);
        try {
            $addr = $this->allocator->allocateZeroed((int) $size, $this->pointerAlign);
        } catch (\RuntimeException) {
            $this->returnStatus($runtime, $this->efiError(9));
            return;
        }
        $this->writePtr($outPtr, $addr);
        $this->returnStatus($runtime, 0);
    }

    private function bsLoadImage(RuntimeInterface $runtime): void
    {
        $parentHandle = $this->arg($runtime, 1);
        $devicePathPtr = $this->arg($runtime, 2);
        $sourceBuffer = $this->arg($runtime, 3);
        $sourceSize = $this->arg($runtime, 4);
        $imageHandlePtr = $this->arg($runtime, 5);

        if ($sourceSize > 0x100000) {
            $this->linuxKernelCandidateLoaded = true;
        }

        $path = null;
        $imageData = null;

        if ($sourceBuffer !== 0 && $sourceSize > 0) {
            $imageData = $this->mem->readBytes($sourceBuffer, (int) $sourceSize);
        }

        if ($imageData === null) {
            $path = $this->devicePathToPath($devicePathPtr);
            if ($path !== null) {
                $imageData = $this->readFileFromMedia($path);
            }
        }

        if ($imageData === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $loader = new PELoader();
        $info = $loader->inspect($imageData);
        if ($info['bits'] !== ($this->pointerSize * 8)) {
            $this->returnStatus($runtime, $this->efiError(3));
            return;
        }

        try {
            $base = $this->allocateImageBase($info['sizeOfImage']);
        } catch (\RuntimeException) {
            $this->returnStatus($runtime, $this->efiError(9));
            return;
        }

        $loaded = $loader->load($runtime, $imageData, $base);
        $handle = $this->allocateHandle();

        $this->createLoadedImageProtocol(
            $handle,
            $loaded['base'],
            $loaded['size'],
            $path ?? $this->imagePath,
            $parentHandle,
            $this->systemTable,
        );

        $this->loadedImages[$handle] = [
            'entry' => $loaded['entry'],
            'base' => $loaded['base'],
            'size' => $loaded['size'],
            'bits' => $loaded['bits'],
            'path' => $path ?? $this->imagePath,
        ];

        $this->maybeRegisterLinuxKernel($runtime, $handle, $loaded, $path ?? $this->imagePath, $imageData);

        if ($this->loadLogCount < 50) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.LoadImage: handle=0x%08X base=0x%08X size=0x%X path=%s',
                $handle & 0xFFFFFFFF,
                $loaded['base'] & 0xFFFFFFFF,
                $loaded['size'],
                $path ?? 'buffer',
            ));
            $this->loadLogCount++;
        }

        $this->writePtr($imageHandlePtr, $handle);
        $this->returnStatus($runtime, 0);
    }

    private function bsStartImage(RuntimeInterface $runtime): void
    {
        $handle = $this->arg($runtime, 0);
        $info = $this->loadedImages[$handle] ?? null;
        if ($info === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }
        if ($info['bits'] !== ($this->pointerSize * 8)) {
            $this->returnStatus($runtime, $this->efiError(3));
            return;
        }

        if ($this->loadLogCount < 50) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.StartImage: handle=0x%08X entry=0x%08X',
                $handle & 0xFFFFFFFF,
                $info['entry'] & 0xFFFFFFFF,
            ));
            $this->loadLogCount++;
        }

        $this->dispatcher->requestSkipReturnPop();
        $this->startImageEntry($runtime, $handle, $info['entry'], $info['bits']);
        $this->returnStatus($runtime, 0);
    }

    private function startImageEntry(RuntimeInterface $runtime, int $handle, int $entry, int $bits): void
    {
        $stackSize = 0x20000;
        $stackBase = $this->allocateStack($stackSize);
        $stackTop = ($stackBase + $stackSize) & ~0xF;
        $ma = $runtime->memoryAccessor();

        if ($bits === 64) {
            $rsp = $stackTop - 0x28;
            $ma->writeBySize(RegisterType::ESP, $rsp, 64);
            $ma->writeBySize(RegisterType::ECX, $handle, 64);
            $ma->writeBySize(RegisterType::EDX, $this->systemTable, 64);
        } else {
            $entryEsp = $stackTop - 12;
            $ma->writeBySize(RegisterType::ESP, $entryEsp, 32);
            $ma->writeBySize($entryEsp, 0, 32);
            $ma->writeBySize($entryEsp + 4, $handle, 32);
            $ma->writeBySize($entryEsp + 8, $this->systemTable, 32);
        }

        $runtime->memory()->setOffset($entry);
    }

    private function bsCreateEvent(RuntimeInterface $runtime): void
    {
        $type = $this->arg($runtime, 0);
        $outPtr = $this->arg($runtime, 4);
        $handle = $this->allocateHandle();
        $this->events[$handle] = [
            'signaled' => false,
            'timer_deadline' => null,
            'timer_period' => 0.0,
            'type' => $type,
        ];
        $this->writePtr($outPtr, $handle);
        $this->returnStatus($runtime, 0);
    }

    private function bsSetTimer(RuntimeInterface $runtime): void
    {
        $event = $this->arg($runtime, 0);
        $type = $this->arg($runtime, 1);
        $trigger = $this->arg($runtime, 2);

        if (!isset($this->events[$event])) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $info = $this->events[$event];

        if ($type === 0) {
            $info['timer_deadline'] = null;
            $info['timer_period'] = 0.0;
            $info['signaled'] = false;
            $this->events[$event] = $info;
            $this->returnStatus($runtime, 0);
            return;
        }

        $seconds = ((float) $trigger) / 10000000.0;
        $now = microtime(true);
        $info['timer_deadline'] = $now + $seconds;
        $info['timer_period'] = $type === 1 ? $seconds : 0.0;
        $info['signaled'] = false;
        $this->events[$event] = $info;
        $this->returnStatus($runtime, 0);
    }

    private function bsSignalEvent(RuntimeInterface $runtime): void
    {
        $event = $this->arg($runtime, 0);
        if (!isset($this->events[$event])) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }
        $this->events[$event]['signaled'] = true;
        $this->returnStatus($runtime, 0);
    }

    private function bsCloseEvent(RuntimeInterface $runtime): void
    {
        $event = $this->arg($runtime, 0);
        unset($this->events[$event]);
        $this->returnStatus($runtime, 0);
    }

    private function bsStall(RuntimeInterface $runtime): void
    {
        $microseconds = $this->arg($runtime, 0);
        if ($microseconds > 0) {
            usleep((int) $microseconds);
        }
        $this->returnStatus($runtime, 0);
    }

    private function pollEvent(int $event, ?KeyboardContextInterface $keyboard = null, ?float $now = null): bool
    {
        if ($event === $this->waitForKeyEvent) {
            return $keyboard !== null && $keyboard->hasKey();
        }

        $info = $this->events[$event] ?? null;
        if ($info === null) {
            return false;
        }

        if (!empty($info['signaled'])) {
            $info['signaled'] = false;
            $this->events[$event] = $info;
            return true;
        }

        $deadline = $info['timer_deadline'] ?? null;
        if ($deadline === null) {
            return false;
        }

        $now = $now ?? microtime(true);
        if ($now < $deadline) {
            return false;
        }

        $period = (float) ($info['timer_period'] ?? 0.0);
        if ($period > 0) {
            $info['timer_deadline'] = $now + $period;
        } else {
            $info['timer_deadline'] = null;
        }
        $this->events[$event] = $info;
        return true;
    }

    private function bsWaitForEvent(RuntimeInterface $runtime): void
    {
        $count = $this->arg($runtime, 0);
        $eventsPtr = $this->arg($runtime, 1);
        $indexPtr = $this->arg($runtime, 2);

        if ($count <= 0 || $eventsPtr === 0 || $indexPtr === 0) {
            $this->returnStatus($runtime, $this->efiError(2));
            return;
        }

        $keyboard = $this->firstKeyboard();
        $sleepCap = 0.05;

        while (true) {
            $now = microtime(true);
            $nextDeadline = null;

            for ($i = 0; $i < $count; $i++) {
                $event = $this->readPtr($eventsPtr + ($i * $this->pointerSize));
                if ($this->pollEvent($event, $keyboard, $now)) {
                    $this->writeUintN($indexPtr, $i);
                    $this->returnStatus($runtime, 0);
                    return;
                }
                $info = $this->events[$event] ?? null;
                $deadline = $info['timer_deadline'] ?? null;
                if ($deadline !== null && ($nextDeadline === null || $deadline < $nextDeadline)) {
                    $nextDeadline = $deadline;
                }
            }

            if ($keyboard !== null && !$keyboard->hasKey()) {
                $keyboard->setWaitingForKey(true);
            }

            if ($nextDeadline === null) {
                usleep((int) ($sleepCap * 1000000));
                continue;
            }

            $sleep = $nextDeadline - microtime(true);
            if ($sleep > 0) {
                usleep((int) (min($sleep, $sleepCap) * 1000000));
            }
        }
    }

    private function bsCheckEvent(RuntimeInterface $runtime): void
    {
        $event = $this->arg($runtime, 0);
        $keyboard = $this->firstKeyboard();
        if (!$this->pollEvent($event, $keyboard)) {
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }
        $this->returnStatus($runtime, 0);
    }

    private function bsGetMemoryMap(RuntimeInterface $runtime): void
    {
        $sizePtr = $this->arg($runtime, 0);
        $mapPtr = $this->arg($runtime, 1);
        $mapKeyPtr = $this->arg($runtime, 2);
        $descSizePtr = $this->arg($runtime, 3);
        $descVersionPtr = $this->arg($runtime, 4);

        $descriptors = $this->memoryMapDescriptors();
        $descSize = 40;
        $required = $descSize * count($descriptors);
        $provided = $this->readUintN($sizePtr);

        $logDescriptors = ($this->memoryMapLogCount === 0);
        if ($this->memoryMapLogCount < 10) {
            $runtime->option()->logger()->warning(sprintf(
                'MEMORY_MAP: provided=%d required=%d mapPtr=0x%X descSize=%d entries=%d',
                $provided,
                $required,
                $mapPtr,
                $descSize,
                count($descriptors),
            ));
            $this->memoryMapLogCount++;
        }
        if ($logDescriptors) {
            $limit = min(16, count($descriptors));
            for ($i = 0; $i < $limit; $i++) {
                $desc = $descriptors[$i];
                $runtime->option()->logger()->warning(sprintf(
                    'MEMORY_DESC[%d]: type=%d phys=0x%X pages=%d attr=0x%X',
                    $i,
                    (int) $desc['type'],
                    (int) $desc['phys'],
                    (int) $desc['pages'],
                    (int) $desc['attr'],
                ));
            }
        }

        if ($mapPtr === 0 || $provided < $required) {
            $this->writeUintN($sizePtr, $required);
            if ($mapKeyPtr !== 0) {
                $this->writeUintN($mapKeyPtr, $this->mapKey);
            }
            if ($descSizePtr !== 0) {
                $this->writeUintN($descSizePtr, $descSize);
            }
            if ($descVersionPtr !== 0) {
                $this->mem->writeU32($descVersionPtr, 1);
            }
            $this->returnStatus($runtime, $this->efiError(5));
            return;
        }

        $offset = $mapPtr;
        foreach ($descriptors as $desc) {
            $this->mem->writeU32($offset, $desc['type']);
            $this->mem->writeU32($offset + 4, 0);
            $this->mem->writeU64($offset + 8, $desc['phys']);
            $this->mem->writeU64($offset + 16, $desc['virt']);
            $this->mem->writeU64($offset + 24, $desc['pages']);
            $this->mem->writeU64($offset + 32, $desc['attr']);
            $offset += $descSize;
        }

        $this->mapKey++;
        $this->writeUintN($sizePtr, $required);
        $this->writeUintN($mapKeyPtr, $this->mapKey);
        $this->writeUintN($descSizePtr, $descSize);
        $this->mem->writeU32($descVersionPtr, 1);

        $this->returnStatus($runtime, 0);
    }

    private function bsExitBootServices(RuntimeInterface $runtime): void
    {
        if ($this->exitBootServicesLogCount < 5) {
            $mapKey = $this->arg($runtime, 1);
            $runtime->option()->logger()->warning(sprintf(
                'BS.ExitBootServices: mapKey=0x%X',
                $mapKey & 0xFFFFFFFF,
            ));
            $this->exitBootServicesLogCount++;
        }
        $this->returnStatus($runtime, 0);
    }

    private function bsGetNextMonotonicCount(RuntimeInterface $runtime): void
    {
        $countPtr = $this->arg($runtime, 0);
        $this->mem->writeU64($countPtr, (int) (microtime(true) * 1000000));
        $this->returnStatus($runtime, 0);
    }

    private function bsHandleProtocol(RuntimeInterface $runtime): void
    {
        $handle = $this->arg($runtime, 0);
        $guidPtr = $this->arg($runtime, 1);
        $interfacePtr = $this->arg($runtime, 2);

        $guid = $this->mem->readGuid($guidPtr);
        if ($this->protocolLogCount < 200) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.HandleProtocol: handle=0x%08X guid=%s',
                $handle & 0xFFFFFFFF,
                $guid,
            ));
            $this->protocolLogCount++;
        }
        $iface = $this->handleProtocolInterface($handle, $guid);
        if ($iface === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->writePtr($interfacePtr, $iface);
        $this->returnStatus($runtime, 0);
    }

    private function bsOpenProtocol(RuntimeInterface $runtime): void
    {
        $handle = $this->arg($runtime, 0);
        $guidPtr = $this->arg($runtime, 1);
        $interfacePtr = $this->arg($runtime, 2);

        $guid = $this->mem->readGuid($guidPtr);
        if ($this->protocolLogCount < 200) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.OpenProtocol: handle=0x%08X guid=%s',
                $handle & 0xFFFFFFFF,
                $guid,
            ));
            $this->protocolLogCount++;
        }
        $iface = $this->handleProtocolInterface($handle, $guid);
        if ($iface === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->writePtr($interfacePtr, $iface);
        $this->returnStatus($runtime, 0);
    }

    private function bsLocateProtocol(RuntimeInterface $runtime): void
    {
        $guidPtr = $this->arg($runtime, 0);
        $interfacePtr = $this->arg($runtime, 2);

        $guid = strtolower($this->mem->readGuid($guidPtr));
        if ($this->protocolLogCount < 200) {
            $runtime->option()->logger()->warning(sprintf('BS.LocateProtocol: guid=%s', $guid));
            $this->protocolLogCount++;
        }
        $iface = $this->protocolRegistry[$guid] ?? null;
        if ($iface === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->writePtr($interfacePtr, $iface);
        $this->returnStatus($runtime, 0);
    }

    private function bsLocateHandleBuffer(RuntimeInterface $runtime): void
    {
        $guidPtr = $this->arg($runtime, 1);
        $countPtr = $this->arg($runtime, 3);
        $bufferPtr = $this->arg($runtime, 4);

        $guid = strtolower($this->mem->readGuid($guidPtr));
        $handles = [];
        foreach ($this->handles as $handle => $info) {
            if (isset($info['protocols'][$guid])) {
                $handles[] = $handle;
            }
        }

        $count = count($handles);
        if ($this->locateHandleLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.LocateHandleBuffer: guid=%s count=%d',
                $guid,
                $count,
            ));
            $this->locateHandleLogCount++;
        }
        if ($count === 0) {
            $this->writeUintN($countPtr, 0);
            $this->writePtr($bufferPtr, 0);
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $arrayAddr = $this->allocator->allocateZeroed($count * $this->pointerSize, $this->pointerAlign);
        $offset = $arrayAddr;
        foreach ($handles as $handle) {
            $this->writePtr($offset, $handle);
            $offset += $this->pointerSize;
        }

        $this->writeUintN($countPtr, $count);
        $this->writePtr($bufferPtr, $arrayAddr);
        $this->returnStatus($runtime, 0);
    }

    private function bsLocateHandle(RuntimeInterface $runtime): void
    {
        $searchType = $this->arg($runtime, 0);
        $guidPtr = $this->arg($runtime, 1);
        $bufferSizePtr = $this->arg($runtime, 3);
        $bufferPtr = $this->arg($runtime, 4);

        $guid = $guidPtr !== 0 ? strtolower($this->mem->readGuid($guidPtr)) : '';
        if ($this->protocolLogCount < 200) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.LocateHandle: type=%d guid=%s',
                $searchType,
                $guid,
            ));
            $this->protocolLogCount++;
        }

        if ($searchType !== 0 && $guid === '') {
            $this->returnStatus($runtime, $this->efiError(3));
            return;
        }
        $handles = [];
        foreach ($this->handles as $handle => $info) {
            if (isset($info['protocols'][$guid])) {
                $handles[] = $handle;
            }
        }

        if ($this->locateHandleLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'BS.LocateHandle: guid=%s count=%d',
                $guid,
                count($handles),
            ));
            $this->locateHandleLogCount++;
        }

        $required = count($handles) * $this->pointerSize;
        $provided = $this->readUintN($bufferSizePtr);
        if ($provided < $required) {
            $this->writeUintN($bufferSizePtr, $required);
            $this->returnStatus($runtime, $this->efiError(5));
            return;
        }

        if ($bufferPtr !== 0) {
            $offset = $bufferPtr;
            foreach ($handles as $handle) {
                $this->writePtr($offset, $handle);
                $offset += $this->pointerSize;
            }
        }

        $this->writeUintN($bufferSizePtr, $required);
        $this->returnStatus($runtime, $required === 0 ? $this->efiError(20) : 0);
    }

    private function bsLocateDevicePath(RuntimeInterface $runtime): void
    {
        $guidPtr = $this->arg($runtime, 0);
        $devicePathPtr = $this->arg($runtime, 1);
        $deviceHandlePtr = $this->arg($runtime, 2);

        $guid = strtolower($this->mem->readGuid($guidPtr));
        if (
            !in_array($guid, [
            strtolower(self::GUID_SIMPLE_FS),
            strtolower(self::GUID_BLOCK_IO),
            strtolower(self::GUID_DISK_IO),
            ], true)
        ) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->writePtr($deviceHandlePtr, $this->deviceHandle);
        if ($devicePathPtr !== 0) {
            $path = $this->readPtr($devicePathPtr);
            $this->writePtr($devicePathPtr, $path);
        }
        $this->returnStatus($runtime, 0);
    }

    private function bsCalculateCrc32(RuntimeInterface $runtime): void
    {
        $dataPtr = $this->arg($runtime, 0);
        $dataSize = $this->arg($runtime, 1);
        $outPtr = $this->arg($runtime, 2);

        $data = $this->mem->readBytes($dataPtr, (int) $dataSize);
        $crc = $this->crc32($data);
        $this->mem->writeU32($outPtr, $crc);
        $this->returnStatus($runtime, 0);
    }

    private function bsCopyMem(RuntimeInterface $runtime): void
    {
        $dest = $this->arg($runtime, 0);
        $src = $this->arg($runtime, 1);
        $len = $this->arg($runtime, 2);
        if ($len > 0) {
            $data = $this->mem->readBytes($src, (int) $len);
            $this->mem->writeBytes($dest, $data);
        }
        $this->returnStatus($runtime, 0);
    }

    private function bsSetMem(RuntimeInterface $runtime): void
    {
        $dest = $this->arg($runtime, 0);
        $len = $this->arg($runtime, 1);
        $value = $this->arg($runtime, 2) & 0xFF;
        if ($len > 0) {
            $this->mem->writeBytes($dest, str_repeat(chr($value), (int) $len));
        }
        $this->returnStatus($runtime, 0);
    }

    private function rsGetTime(RuntimeInterface $runtime): void
    {
        $timePtr = $this->arg($runtime, 0);
        $this->writeEfiTime($timePtr);
        $this->returnStatus($runtime, 0);
    }

    private function memoryMapDescriptors(): array
    {
        $memorySize = $this->runtime->logicBoard()->memory()->initialMemory();
        $regions = [];

        $lowReservedEnd = min($this->pageAllocBase, $memorySize);
        if ($lowReservedEnd > 0) {
            $regions[] = [
                'start' => 0,
                'end' => $lowReservedEnd,
                'type' => self::EFI_MEMORY_TYPE_RESERVED,
            ];
        }

        $imageStart = $this->imageBase;
        $imageEnd = $this->imageBase + $this->imageSize;
        if ($imageEnd > $imageStart) {
            $regions[] = [
                'start' => $imageStart,
                'end' => $imageEnd,
                'type' => self::EFI_MEMORY_TYPE_LOADER_CODE,
            ];
        }

        foreach ($this->loadedImages as $info) {
            $start = $info['base'] ?? 0;
            $end = $start + ($info['size'] ?? 0);
            if ($end > $start) {
                $regions[] = [
                    'start' => $start,
                    'end' => $end,
                    'type' => self::EFI_MEMORY_TYPE_LOADER_CODE,
                ];
            }
        }

        foreach ($this->pageAllocations as $alloc) {
            $start = (int) ($alloc['start'] ?? 0);
            $end = (int) ($alloc['end'] ?? 0);
            if ($end > $start) {
                $regions[] = [
                    'start' => $start,
                    'end' => $end,
                    'type' => (int) ($alloc['type'] ?? self::EFI_MEMORY_TYPE_BOOT_SERVICES_DATA),
                ];
            }
        }

        $allocStart = $this->allocator->base();
        $allocEnd = $this->allocator->cursor();
        if ($allocEnd > $allocStart) {
            $regions[] = [
                'start' => $allocStart,
                'end' => $allocEnd,
                'type' => self::EFI_MEMORY_TYPE_BOOT_SERVICES_DATA,
            ];
        }

        $regions = array_values(array_filter($regions, function (array $r) use ($memorySize): bool {
            return ($r['end'] > 0) && ($r['start'] < $memorySize) && ($r['end'] > $r['start']);
        }));

        usort($regions, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        $descriptors = [];
        $pos = 0;

        $addDesc = static function (array &$out, int $type, int $start, int $end): void {
            $start = $start & ~0xFFF;
            $end = ($end + 0xFFF) & ~0xFFF;
            if ($end <= $start) {
                return;
            }
            $pages = intdiv($end - $start + 4095, 4096);
            if ($pages <= 0) {
                return;
            }
            $out[] = [
                'type' => $type,
                'phys' => $start,
                'virt' => 0,
                'pages' => $pages,
                'attr' => 0,
            ];
        };

        foreach ($regions as $region) {
            $start = max(0, (int) $region['start']);
            $end = min($memorySize, (int) $region['end']);
            if ($end <= $pos) {
                continue;
            }
            if ($start < $pos) {
                $start = $pos;
            }
            if ($start > $pos) {
                $addDesc($descriptors, self::EFI_MEMORY_TYPE_CONVENTIONAL, $pos, $start);
            }
            $addDesc($descriptors, (int) $region['type'], $start, $end);
            $pos = max($pos, $end);
            $pos = ($pos + 0xFFF) & ~0xFFF;
        }

        if ($pos < $memorySize) {
            $addDesc($descriptors, self::EFI_MEMORY_TYPE_CONVENTIONAL, $pos, $memorySize);
        }

        return $descriptors;
    }
}
