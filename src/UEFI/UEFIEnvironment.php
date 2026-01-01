<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Device\KeyboardContextInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\ISO\ISO9660;

final class UEFIEnvironment
{
    private const EFI_SYSTEM_TABLE_SIGNATURE = 0x5453595320494249; // 'IBI SYST'
    private const EFI_BOOT_SERVICES_SIGNATURE = 0x56524553544F4F42; // 'BOOTSERV'
    private const EFI_RUNTIME_SERVICES_SIGNATURE = 0x56524553544E5552; // 'RUNTSERV'
    private const EFI_REVISION = 0x00020000;

    private const EFI_ERROR_BIT = -9223372036854775808; // 0x8000000000000000

    private const EFI_FILE_MODE_READ = 0x0000000000000001;
    private const EFI_FILE_DIRECTORY = 0x0000000000000010;

    private const EFI_MEMORY_TYPE_CONVENTIONAL = 7;

    private const GUID_LOADED_IMAGE = '5b1b31a1-9562-11d2-8e3f-00a0c969723b';
    private const GUID_SIMPLE_FS = '964e5b22-6459-11d2-8e39-00a0c969723b';
    private const GUID_SIMPLE_TEXT_IN = '387477c1-69c7-11d2-8e39-00a0c969723b';
    private const GUID_SIMPLE_TEXT_OUT = '387477c2-69c7-11d2-8e39-00a0c969723b';
    private const GUID_FILE_INFO = '09576e92-6d3f-11d2-8e39-00a0c969723b';
    private const GUID_FS_INFO = '09576e93-6d3f-11d2-8e39-00a0c969723b';
    private const GUID_DEVICE_PATH = '09576e91-6d3f-11d2-8e39-00a0c969723b';

    private const FILE_PROTOCOL_REVISION = 0x00010000;
    private const SIMPLE_FS_REVISION = 0x00010000;
    private const LOADED_IMAGE_REVISION = 0x00001000;

    private RuntimeInterface $runtime;
    private ISO9660 $iso;
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

    private int $simpleTextIn = 0;
    private int $simpleTextOut = 0;
    private int $simpleTextOutMode = 0;
    private int $waitForKeyEvent = 0;

    /** @var array<int, array{protocols: array<string, int>}> */
    private array $handles = [];

    /** @var array<string, int> */
    private array $protocolRegistry = [];

    /** @var array<int, array{path: string, isDir: bool, data: string, position: int, entries: array<int, array<string, mixed>>|null, entryIndex: int}> */
    private array $fileHandles = [];

    /** @var array<int, array{signaled: bool}> */
    private array $events = [];

    private int $mapKey = 1;

    public function __construct(
        RuntimeInterface $runtime,
        ISO9660 $iso,
        int $imageBase,
        int $imageSize,
        string $imagePath,
        int $allocBase = 0x06000000,
        int $allocLimit = 0x0A000000,
    ) {
        $this->runtime = $runtime;
        $this->iso = $iso;
        $this->imageBase = $imageBase;
        $this->imageSize = $imageSize;
        $this->imagePath = $imagePath;

        $this->dispatcher = new UEFIDispatcher();
        $this->mem = new UEFIMemory($runtime);
        $this->allocator = new UEFIAllocator($this->mem, $allocBase, $allocLimit);
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

    public function build(): int
    {
        $this->imageHandle = $this->allocateHandle();
        $this->deviceHandle = $this->allocateHandle();
        $this->consoleInHandle = $this->allocateHandle();
        $this->consoleOutHandle = $this->allocateHandle();

        $this->buildTextInput();
        $this->buildTextOutput();
        $this->buildSimpleFileSystem();
        $this->buildLoadedImage();

        $this->runtimeServices = $this->buildRuntimeServices();
        $this->bootServices = $this->buildBootServices();
        $this->systemTable = $this->buildSystemTable();

        $this->mem->writeU64($this->loadedImageProtocol + 16, $this->systemTable);

        return $this->systemTable;
    }

    public function allocateStack(int $size): int
    {
        return $this->allocator->allocateZeroed($size, 16);
    }

    private function buildTextInput(): void
    {
        $reset = $this->dispatcher->register('TextIn.Reset', fn(RuntimeInterface $runtime) => $this->textInReset($runtime));
        $readKey = $this->dispatcher->register('TextIn.ReadKeyStroke', fn(RuntimeInterface $runtime) => $this->textInReadKeyStroke($runtime));

        $this->waitForKeyEvent = $this->allocateHandle();
        $this->events[$this->waitForKeyEvent] = ['signaled' => false];

        $this->simpleTextIn = $this->allocator->allocateZeroed(24, 8);
        $this->mem->writeU64($this->simpleTextIn, $reset);
        $this->mem->writeU64($this->simpleTextIn + 8, $readKey);
        $this->mem->writeU64($this->simpleTextIn + 16, $this->waitForKeyEvent);

        $this->registerHandleProtocol($this->consoleInHandle, self::GUID_SIMPLE_TEXT_IN, $this->simpleTextIn);
        $this->protocolRegistry[self::GUID_SIMPLE_TEXT_IN] = $this->simpleTextIn;
    }

    private function buildTextOutput(): void
    {
        $reset = $this->dispatcher->register('TextOut.Reset', fn(RuntimeInterface $runtime) => $this->textOutReset($runtime));
        $output = $this->dispatcher->register('TextOut.OutputString', fn(RuntimeInterface $runtime) => $this->textOutOutputString($runtime));
        $test = $this->dispatcher->register('TextOut.TestString', fn(RuntimeInterface $runtime) => $this->textOutTestString($runtime));
        $query = $this->dispatcher->register('TextOut.QueryMode', fn(RuntimeInterface $runtime) => $this->textOutQueryMode($runtime));
        $setMode = $this->dispatcher->register('TextOut.SetMode', fn(RuntimeInterface $runtime) => $this->textOutSetMode($runtime));
        $setAttr = $this->dispatcher->register('TextOut.SetAttribute', fn(RuntimeInterface $runtime) => $this->textOutSetAttribute($runtime));
        $clear = $this->dispatcher->register('TextOut.ClearScreen', fn(RuntimeInterface $runtime) => $this->textOutClearScreen($runtime));
        $setCursor = $this->dispatcher->register('TextOut.SetCursorPosition', fn(RuntimeInterface $runtime) => $this->textOutSetCursorPosition($runtime));
        $enableCursor = $this->dispatcher->register('TextOut.EnableCursor', fn(RuntimeInterface $runtime) => $this->textOutEnableCursor($runtime));

        $this->simpleTextOutMode = $this->allocator->allocateZeroed(32, 8);
        $this->mem->writeU32($this->simpleTextOutMode, 1);
        $this->mem->writeU32($this->simpleTextOutMode + 4, 0);
        $this->mem->writeU32($this->simpleTextOutMode + 8, 0x07);
        $this->mem->writeU32($this->simpleTextOutMode + 12, 0);
        $this->mem->writeU32($this->simpleTextOutMode + 16, 0);
        $this->mem->writeU32($this->simpleTextOutMode + 20, 1);

        $this->simpleTextOut = $this->allocator->allocateZeroed(80, 8);
        $this->mem->writeU64($this->simpleTextOut, $reset);
        $this->mem->writeU64($this->simpleTextOut + 8, $output);
        $this->mem->writeU64($this->simpleTextOut + 16, $test);
        $this->mem->writeU64($this->simpleTextOut + 24, $query);
        $this->mem->writeU64($this->simpleTextOut + 32, $setMode);
        $this->mem->writeU64($this->simpleTextOut + 40, $setAttr);
        $this->mem->writeU64($this->simpleTextOut + 48, $clear);
        $this->mem->writeU64($this->simpleTextOut + 56, $setCursor);
        $this->mem->writeU64($this->simpleTextOut + 64, $enableCursor);
        $this->mem->writeU64($this->simpleTextOut + 72, $this->simpleTextOutMode);

        $this->registerHandleProtocol($this->consoleOutHandle, self::GUID_SIMPLE_TEXT_OUT, $this->simpleTextOut);
        $this->protocolRegistry[self::GUID_SIMPLE_TEXT_OUT] = $this->simpleTextOut;
    }

    private function buildSimpleFileSystem(): void
    {
        $openVolume = $this->dispatcher->register('SimpleFS.OpenVolume', fn(RuntimeInterface $runtime) => $this->simpleFsOpenVolume($runtime));

        $this->simpleFileSystem = $this->allocator->allocateZeroed(16, 8);
        $this->mem->writeU64($this->simpleFileSystem, self::SIMPLE_FS_REVISION);
        $this->mem->writeU64($this->simpleFileSystem + 8, $openVolume);

        $this->registerHandleProtocol($this->deviceHandle, self::GUID_SIMPLE_FS, $this->simpleFileSystem);
        $this->protocolRegistry[self::GUID_SIMPLE_FS] = $this->simpleFileSystem;
    }

    private function buildLoadedImage(): void
    {
        $unload = $this->dispatcher->register('LoadedImage.Unload', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $filePath = $this->buildFilePathDevicePath($this->imagePath);

        $this->loadedImageProtocol = $this->allocator->allocateZeroed(96, 8);
        $this->mem->writeU32($this->loadedImageProtocol, self::LOADED_IMAGE_REVISION);
        $this->mem->writeU32($this->loadedImageProtocol + 4, 0);
        $this->mem->writeU64($this->loadedImageProtocol + 8, 0);
        $this->mem->writeU64($this->loadedImageProtocol + 16, 0);
        $this->mem->writeU64($this->loadedImageProtocol + 24, $this->deviceHandle);
        $this->mem->writeU64($this->loadedImageProtocol + 32, $filePath);
        $this->mem->writeU64($this->loadedImageProtocol + 40, 0);
        $this->mem->writeU32($this->loadedImageProtocol + 48, 0);
        $this->mem->writeU32($this->loadedImageProtocol + 52, 0);
        $this->mem->writeU64($this->loadedImageProtocol + 56, 0);
        $this->mem->writeU64($this->loadedImageProtocol + 64, $this->imageBase);
        $this->mem->writeU64($this->loadedImageProtocol + 72, $this->imageSize);
        $this->mem->writeU32($this->loadedImageProtocol + 80, 1);
        $this->mem->writeU32($this->loadedImageProtocol + 84, 2);
        $this->mem->writeU64($this->loadedImageProtocol + 88, $unload);

        $this->registerHandleProtocol($this->imageHandle, self::GUID_LOADED_IMAGE, $this->loadedImageProtocol);
        $this->registerHandleProtocol($this->imageHandle, self::GUID_DEVICE_PATH, $filePath);
        $this->protocolRegistry[self::GUID_LOADED_IMAGE] = $this->loadedImageProtocol;
    }

    private function buildSystemTable(): int
    {
        $vendor = $this->allocator->allocateZeroed(64, 2);
        $this->mem->writeUtf16String($vendor, 'PHPME');

        $size = 120;
        $addr = $this->allocator->allocateZeroed($size, 8);
        $this->writeTableHeader($addr, self::EFI_SYSTEM_TABLE_SIGNATURE, self::EFI_REVISION, $size);

        $this->mem->writeU64($addr + 24, $vendor);
        $this->mem->writeU32($addr + 32, 0x00010000);
        $this->mem->writeU32($addr + 36, 0);
        $this->mem->writeU64($addr + 40, $this->consoleInHandle);
        $this->mem->writeU64($addr + 48, $this->simpleTextIn);
        $this->mem->writeU64($addr + 56, $this->consoleOutHandle);
        $this->mem->writeU64($addr + 64, $this->simpleTextOut);
        $this->mem->writeU64($addr + 72, $this->consoleOutHandle);
        $this->mem->writeU64($addr + 80, $this->simpleTextOut);
        $this->mem->writeU64($addr + 88, $this->runtimeServices);
        $this->mem->writeU64($addr + 96, $this->bootServices);
        $this->mem->writeU64($addr + 104, 0);
        $this->mem->writeU64($addr + 112, 0);

        $this->updateTableCrc($addr, $size);

        return $addr;
    }

    private function buildBootServices(): int
    {
        $pointers = [];
        $pointers[] = $this->dispatcher->register('BS.RaiseTPL', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.RestoreTPL', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.AllocatePages', fn(RuntimeInterface $runtime) => $this->bsAllocatePages($runtime));
        $pointers[] = $this->dispatcher->register('BS.FreePages', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.GetMemoryMap', fn(RuntimeInterface $runtime) => $this->bsGetMemoryMap($runtime));
        $pointers[] = $this->dispatcher->register('BS.AllocatePool', fn(RuntimeInterface $runtime) => $this->bsAllocatePool($runtime));
        $pointers[] = $this->dispatcher->register('BS.FreePool', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.CreateEvent', fn(RuntimeInterface $runtime) => $this->bsCreateEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.SetTimer', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.WaitForEvent', fn(RuntimeInterface $runtime) => $this->bsWaitForEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.SignalEvent', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.CloseEvent', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.CheckEvent', fn(RuntimeInterface $runtime) => $this->bsCheckEvent($runtime));
        $pointers[] = $this->dispatcher->register('BS.InstallProtocolInterface', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.ReinstallProtocolInterface', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.UninstallProtocolInterface', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.HandleProtocol', fn(RuntimeInterface $runtime) => $this->bsHandleProtocol($runtime));
        $pointers[] = 0;
        $pointers[] = $this->dispatcher->register('BS.RegisterProtocolNotify', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.LocateHandle', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.LocateDevicePath', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.InstallConfigurationTable', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.LoadImage', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.StartImage', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, $this->efiError(3)));
        $pointers[] = $this->dispatcher->register('BS.Exit', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.UnloadImage', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $pointers[] = $this->dispatcher->register('BS.ExitBootServices', fn(RuntimeInterface $runtime) => $this->bsExitBootServices($runtime));
        $pointers[] = $this->dispatcher->register('BS.GetNextMonotonicCount', fn(RuntimeInterface $runtime) => $this->bsGetNextMonotonicCount($runtime));
        $pointers[] = $this->dispatcher->register('BS.Stall', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
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

        $size = 24 + (count($pointers) * 8);
        $addr = $this->allocator->allocateZeroed($size, 8);
        $this->writeTableHeader($addr, self::EFI_BOOT_SERVICES_SIGNATURE, self::EFI_REVISION, $size);

        $offset = $addr + 24;
        foreach ($pointers as $ptr) {
            $this->mem->writeU64($offset, $ptr);
            $offset += 8;
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

        $size = 24 + (count($pointers) * 8);
        $addr = $this->allocator->allocateZeroed($size, 8);
        $this->writeTableHeader($addr, self::EFI_RUNTIME_SERVICES_SIGNATURE, self::EFI_REVISION, $size);

        $offset = $addr + 24;
        foreach ($pointers as $ptr) {
            $this->mem->writeU64($offset, $ptr);
            $offset += 8;
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
        $handle = $this->allocator->allocateZeroed(8, 8);
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
        return match ($index) {
            0 => $ma->fetch(RegisterType::ECX)->asBytesBySize(64),
            1 => $ma->fetch(RegisterType::EDX)->asBytesBySize(64),
            2 => $ma->fetch(RegisterType::R8)->asBytesBySize(64),
            3 => $ma->fetch(RegisterType::R9)->asBytesBySize(64),
            default => $this->mem->readU64($this->stackArgAddress($runtime, $index)),
        };
    }

    private function stackArgAddress(RuntimeInterface $runtime, int $index): int
    {
        $rsp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(64);
        return $rsp + 0x20 + (($index - 4) * 8);
    }

    private function returnStatus(RuntimeInterface $runtime, int $status): void
    {
        $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $status, 64);
    }

    private function efiError(int $code): int
    {
        return self::EFI_ERROR_BIT | ($code & 0xFFFF);
    }

    private function textOutReset(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutOutputString(RuntimeInterface $runtime): void
    {
        $stringPtr = $this->arg($runtime, 1);
        $text = $this->mem->readUtf16String($stringPtr);
        if ($text !== '') {
            $runtime->context()->screen()->write($text);
        }
        $this->returnStatus($runtime, 0);
    }

    private function textOutTestString(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutQueryMode(RuntimeInterface $runtime): void
    {
        $columnsPtr = $this->arg($runtime, 2);
        $rowsPtr = $this->arg($runtime, 3);
        $this->mem->writeU64($columnsPtr, 80);
        $this->mem->writeU64($rowsPtr, 25);
        $this->returnStatus($runtime, 0);
    }

    private function textOutSetMode(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutSetAttribute(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutClearScreen(RuntimeInterface $runtime): void
    {
        $runtime->context()->screen()->clear();
        $this->returnStatus($runtime, 0);
    }

    private function textOutSetCursorPosition(RuntimeInterface $runtime): void
    {
        $row = $this->arg($runtime, 2);
        $col = $this->arg($runtime, 1);
        $runtime->context()->screen()->setCursorPosition((int) $row, (int) $col);
        $this->returnStatus($runtime, 0);
    }

    private function textOutEnableCursor(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textInReset(RuntimeInterface $runtime): void
    {
        $keyboard = $this->firstKeyboard();
        if ($keyboard !== null) {
            $keyboard->clearBuffer();
        }
        $this->returnStatus($runtime, 0);
    }

    private function textInReadKeyStroke(RuntimeInterface $runtime): void
    {
        $keyPtr = $this->arg($runtime, 1);
        $keyboard = $this->firstKeyboard();
        if ($keyboard === null) {
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }

        $key = $keyboard->dequeueKey();
        if ($key === null) {
            $keyboard->setWaitingForKey(true);
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }

        $keyboard->setWaitingForKey(false);
        $scan = $key['scancode'] & 0xFF;
        $ascii = $key['ascii'] & 0xFF;
        $this->mem->writeU16($keyPtr, $scan);
        $this->mem->writeU16($keyPtr + 2, $ascii);
        $this->returnStatus($runtime, 0);
    }

    private function simpleFsOpenVolume(RuntimeInterface $runtime): void
    {
        $outPtr = $this->arg($runtime, 1);
        $root = $this->openFileHandle('/');
        $this->mem->writeU64($outPtr, $root);
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
        $handle = $this->openFileHandle($path);
        if ($handle === 0) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->mem->writeU64($newHandlePtr, $handle);
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

        $requested = $this->mem->readU64($sizePtr);
        if ($requested <= 0) {
            $this->mem->writeU64($sizePtr, 0);
            $this->returnStatus($runtime, 0);
            return;
        }

        if ($state['isDir']) {
            $this->mem->writeU64($sizePtr, 0);
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

        $this->mem->writeU64($sizePtr, $readLen);
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
        $state = $this->fileHandles[$thisPtr] ?? null;
        if ($state === null) {
            $this->returnStatus($runtime, $this->efiError(2));
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

        $guid = strtolower($this->mem->readGuid($infoTypePtr));
        if ($guid === self::GUID_FILE_INFO) {
            $fileName = $state['path'] === '/' ? '.' : basename($state['path']);
            $nameBytes = $this->utf16Bytes($fileName . "\0");
            $infoSize = 80 + strlen($nameBytes);
            $requested = $this->mem->readU64($sizePtr);

            if ($requested < $infoSize) {
                $this->mem->writeU64($sizePtr, $infoSize);
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

            $this->mem->writeU64($sizePtr, $infoSize);
            $this->returnStatus($runtime, 0);
            return;
        }

        if ($guid === self::GUID_FS_INFO) {
            $label = $this->utf16Bytes("ISO\0");
            $infoSize = 32 + strlen($label);
            $requested = $this->mem->readU64($sizePtr);

            if ($requested < $infoSize) {
                $this->mem->writeU64($sizePtr, $infoSize);
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

            $this->mem->writeU64($sizePtr, $infoSize);
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

    private function openFileHandle(string $path): int
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            $normalized = '/';
        }

        if ($normalized === '/') {
            $entries = $this->iso->readDirectory('/') ?? [];
            return $this->createFileHandle($normalized, true, '', $entries);
        }

        $data = $this->iso->readFile($normalized);
        if ($data !== null) {
            return $this->createFileHandle($normalized, false, $data, null);
        }

        $entries = $this->iso->readDirectory($normalized);
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

        $handle = $this->allocator->allocateZeroed(88, 8);
        $this->mem->writeU64($handle, self::FILE_PROTOCOL_REVISION);
        $this->mem->writeU64($handle + 8, $open);
        $this->mem->writeU64($handle + 16, $close);
        $this->mem->writeU64($handle + 24, $delete);
        $this->mem->writeU64($handle + 32, $read);
        $this->mem->writeU64($handle + 40, $write);
        $this->mem->writeU64($handle + 48, $getPos);
        $this->mem->writeU64($handle + 56, $setPos);
        $this->mem->writeU64($handle + 64, $getInfo);
        $this->mem->writeU64($handle + 72, $setInfo);
        $this->mem->writeU64($handle + 80, $flush);

        $this->fileHandles[$handle] = [
            'path' => $path,
            'isDir' => $isDir,
            'data' => $data,
            'position' => 0,
            'entries' => $entries,
            'entryIndex' => 0,
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

    private function firstKeyboard(): ?KeyboardContextInterface
    {
        foreach ($this->runtime->context()->devices()->keyboards() as $keyboard) {
            return $keyboard;
        }
        return null;
    }

    private function bsAllocatePages(RuntimeInterface $runtime): void
    {
        $pages = $this->arg($runtime, 2);
        $outPtr = $this->arg($runtime, 3);
        $bytes = (int) $pages * 4096;
        try {
            $addr = $this->allocator->allocateZeroed($bytes, 4096);
        } catch (\RuntimeException) {
            $this->returnStatus($runtime, $this->efiError(9));
            return;
        }
        $this->mem->writeU64($outPtr, $addr);
        $this->returnStatus($runtime, 0);
    }

    private function bsAllocatePool(RuntimeInterface $runtime): void
    {
        $size = $this->arg($runtime, 1);
        $outPtr = $this->arg($runtime, 2);
        try {
            $addr = $this->allocator->allocateZeroed((int) $size, 8);
        } catch (\RuntimeException) {
            $this->returnStatus($runtime, $this->efiError(9));
            return;
        }
        $this->mem->writeU64($outPtr, $addr);
        $this->returnStatus($runtime, 0);
    }

    private function bsCreateEvent(RuntimeInterface $runtime): void
    {
        $outPtr = $this->arg($runtime, 4);
        $handle = $this->allocateHandle();
        $this->events[$handle] = ['signaled' => false];
        $this->mem->writeU64($outPtr, $handle);
        $this->returnStatus($runtime, 0);
    }

    private function bsWaitForEvent(RuntimeInterface $runtime): void
    {
        $indexPtr = $this->arg($runtime, 2);
        $keyboard = $this->firstKeyboard();
        if ($keyboard !== null && !$keyboard->hasKey()) {
            $keyboard->setWaitingForKey(true);
        }
        $this->mem->writeU64($indexPtr, 0);
        $this->returnStatus($runtime, 0);
    }

    private function bsCheckEvent(RuntimeInterface $runtime): void
    {
        $event = $this->arg($runtime, 0);
        $keyboard = $this->firstKeyboard();
        if ($event === $this->waitForKeyEvent && $keyboard !== null && !$keyboard->hasKey()) {
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
        $descSize = 48;
        $required = $descSize * count($descriptors);
        $provided = $this->mem->readU64($sizePtr);

        if ($provided < $required) {
            $this->mem->writeU64($sizePtr, $required);
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
        $this->mem->writeU64($sizePtr, $required);
        $this->mem->writeU64($mapKeyPtr, $this->mapKey);
        $this->mem->writeU64($descSizePtr, $descSize);
        $this->mem->writeU32($descVersionPtr, 1);

        $this->returnStatus($runtime, 0);
    }

    private function bsExitBootServices(RuntimeInterface $runtime): void
    {
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
        $iface = $this->handleProtocolInterface($handle, $guid);
        if ($iface === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->mem->writeU64($interfacePtr, $iface);
        $this->returnStatus($runtime, 0);
    }

    private function bsOpenProtocol(RuntimeInterface $runtime): void
    {
        $handle = $this->arg($runtime, 0);
        $guidPtr = $this->arg($runtime, 1);
        $interfacePtr = $this->arg($runtime, 2);

        $guid = $this->mem->readGuid($guidPtr);
        $iface = $this->handleProtocolInterface($handle, $guid);
        if ($iface === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->mem->writeU64($interfacePtr, $iface);
        $this->returnStatus($runtime, 0);
    }

    private function bsLocateProtocol(RuntimeInterface $runtime): void
    {
        $guidPtr = $this->arg($runtime, 0);
        $interfacePtr = $this->arg($runtime, 2);

        $guid = strtolower($this->mem->readGuid($guidPtr));
        $iface = $this->protocolRegistry[$guid] ?? null;
        if ($iface === null) {
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $this->mem->writeU64($interfacePtr, $iface);
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
        if ($count === 0) {
            $this->mem->writeU64($countPtr, 0);
            $this->mem->writeU64($bufferPtr, 0);
            $this->returnStatus($runtime, $this->efiError(20));
            return;
        }

        $arrayAddr = $this->allocator->allocateZeroed($count * 8, 8);
        $offset = $arrayAddr;
        foreach ($handles as $handle) {
            $this->mem->writeU64($offset, $handle);
            $offset += 8;
        }

        $this->mem->writeU64($countPtr, $count);
        $this->mem->writeU64($bufferPtr, $arrayAddr);
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
        $pages = intdiv($memorySize + 4095, 4096);

        return [[
            'type' => self::EFI_MEMORY_TYPE_CONVENTIONAL,
            'phys' => 0,
            'virt' => 0,
            'pages' => $pages,
            'attr' => 0,
        ]];
    }
}
