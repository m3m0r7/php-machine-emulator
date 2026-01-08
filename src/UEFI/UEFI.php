<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Media\MediaContextInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\ISO\BootImage;
use PHPMachineEmulator\Stream\ISO\ElTorito;
use PHPMachineEmulator\Stream\ISO\ISOBootImageStream;
use PHPMachineEmulator\UEFI\UEFIEnvironment\UEFIEnvironmentInterface;

final class UEFI
{
    public const NAME = 'PHPMachineEmulator-UEFI';

    private const PAGE_TABLE_BASE = 0x00100000;
    private const GDT_BASE = 0x00080000;

    public function __construct(
        private RuntimeInterface $runtime,
        private MediaContextInterface $mediaContext,
        private OptionInterface $option,
    ) {
    }

    public static function start(
        RuntimeInterface $runtime,
        MediaContextInterface $mediaContext,
        OptionInterface $option,
    ): void {
        try {
            (new static($runtime, $mediaContext, $option))->boot();
        } catch (HaltException) {
            throw new ExitException('Halted', 0);
        } catch (ExitException $e) {
            throw $e;
        }
    }

    private function boot(): void
    {
        $this->initializeRegisters();
        $this->initializeServices();
        $pic = $this->runtime->context()->cpu()->picState();
        $pic->maskMaster(0xFE);
        $pic->maskSlave(0xFF);
        $this->option->logger()->debug('UEFI: Initialized PIC - IRQ0 enabled, others masked');
        $this->runtime->context()->cpu()->enableA20(true);

        $iso = $this->isoFromMedia();
        [$efiPath, $efiImage, $efiBootImage] = $this->selectEfiImage($iso);
        $source = $efiBootImage === null ? 'iso9660' : 'el-torito';
        $this->option->logger()->info(sprintf('UEFI: selected EFI image %s (%s)', $efiPath, $source));

        $loader = new PELoader();
        $loaded = $loader->load($this->runtime, $efiImage);
        $is64 = ($loaded['bits'] ?? 64) === 64;

        $env = new UEFIEnvironment(
            $this->runtime,
            $iso,
            $loaded['base'],
            $loaded['size'],
            $efiPath,
            allocLimit: $this->runtime->logicBoard()->memory()->maxMemory(),
            pointerSize: $is64 ? 8 : 4,
            bootImage: $efiBootImage,
        );
        $systemTable = $env->build();
        UEFIRuntimeRegistry::register($this->runtime, $env->dispatcher(), $env);

        if ($is64) {
            $this->setupPageTables();
            $this->setupGdt(true);
            $this->enableLongMode();
            $this->setupSegments();
            $this->setupStack($env, 64);

            $ma = $this->runtime->memoryAccessor();
            $ma->writeBySize(RegisterType::ECX, $env->imageHandle(), 64);
            $ma->writeBySize(RegisterType::EDX, $systemTable, 64);
        } else {
            $this->setupGdt(false);
            $this->enableProtectedMode32();
            $this->setupSegments();
            $stackTop = $this->setupStack($env, 32);

            $ma = $this->runtime->memoryAccessor();
            $entryEsp = $stackTop - 12;
            $ma->writeBySize(RegisterType::ESP, $entryEsp, 32);
            $ma->writeBySize($entryEsp, 0, 32);
            $ma->writeBySize($entryEsp + 4, $env->imageHandle(), 32);
            $ma->writeBySize($entryEsp + 8, $systemTable, 32);
        }

        $this->runtime->memory()->setOffset($loaded['entry']);
        $this->runtime->start();
    }

    private function isoFromMedia(): \PHPMachineEmulator\Stream\ISO\ISO9660
    {
        $bootStream = $this->mediaContext->primary()->stream();
        if (!$bootStream instanceof ISOBootImageStream) {
            throw new StreamReaderException('UEFI boot requires ISO media');
        }

        return $bootStream->isoStream()->iso();
    }

    /**
     * @return array{string,string,BootImage|null}
     */
    private function selectEfiImage(\PHPMachineEmulator\Stream\ISO\ISO9660 $iso): array
    {
        $paths = [
            '/EFI/BOOT/GRUBX64.EFI',
            '/EFI/BOOT/BOOTX64.EFI',
            '/EFI/BOOT/GRUBIA32.EFI',
            '/EFI/BOOT/BOOTIA32.EFI',
        ];

        foreach ($paths as $path) {
            $data = $iso->readFile($path);
            if ($data !== null) {
                return [$path, $data, null];
            }
        }

        $bootImage = $this->efiBootImage($iso);
        if ($bootImage !== null) {
            foreach ($paths as $path) {
                $data = $bootImage->readFileByPath($path);
                if ($data !== null) {
                    return [$path, $data, $bootImage];
                }
            }
        }

        throw new StreamReaderException('EFI bootloader not found in ISO');
    }

    private function efiBootImage(\PHPMachineEmulator\Stream\ISO\ISO9660 $iso): ?BootImage
    {
        $bootRecord = $iso->bootRecord();
        if ($bootRecord === null || !$bootRecord->isElTorito()) {
            return null;
        }

        try {
            $elTorito = new ElTorito($iso, $bootRecord->bootCatalogSector);
        } catch (StreamReaderException) {
            return null;
        }

        return $elTorito->getBootImageForPlatform(ElTorito::PLATFORM_EFI);
    }



    private function initializeRegisters(): void
    {
        $mem = $this->runtime->memoryAccessor();
        foreach ([...$this->runtime->register()::map(), $this->runtime->video()->videoTypeFlagAddress()] as $address) {
            $mem->allocate($address);
        }
    }

    private function initializeServices(): void
    {
        foreach ($this->runtime->services() as $service) {
            $service->initialize($this->runtime);
        }
    }

    private function setupPageTables(): void
    {
        $ma = $this->runtime->memoryAccessor();
        $maxMemory = $this->runtime->logicBoard()->memory()->maxMemory();
        $blocks = intdiv($maxMemory + 0x3FFFFFFF, 0x40000000);
        if ($blocks < 1) {
            $blocks = 1;
        }

        $base = self::PAGE_TABLE_BASE;
        $pml4 = $base;
        $pdpt = $base + 0x1000;
        $pdBase = $base + 0x2000;
        $tableBytes = 0x2000 + ($blocks * 0x1000);

        // Identity-map up to maxMemory using 2MB pages.
        $ma->allocate($base, $tableBytes, safe: false);
        for ($i = 0; $i < $tableBytes; $i += 8) {
            $ma->writePhysical64($base + $i, 0);
        }

        $ma->writePhysical64($pml4, $pdpt | 0x003);

        for ($block = 0; $block < $blocks; $block++) {
            $pd = $pdBase + ($block * 0x1000);
            $ma->writePhysical64($pdpt + ($block * 8), $pd | 0x003);
            for ($i = 0; $i < 512; $i++) {
                $addr = ($block * 0x40000000) + ($i * 0x200000);
                $ma->writePhysical64($pd + ($i * 8), $addr | 0x083);
            }
        }
    }

    private function setupGdt(bool $is64): void
    {
        $ma = $this->runtime->memoryAccessor();
        $base = self::GDT_BASE;
        $ma->allocate($base, 24, safe: false);
        $ma->writePhysical64($base, 0x0000000000000000);
        $code = $is64 ? 0x00AF9A000000FFFF : 0x00CF9A000000FFFF;
        $ma->writePhysical64($base + 8, $code);
        $ma->writePhysical64($base + 16, 0x00CF92000000FFFF);

        $this->runtime->context()->cpu()->setGdtr($base, 24 - 1);
    }

    private function enableProtectedMode32(): void
    {
        $cpu = $this->runtime->context()->cpu();
        $cpu->enableA20(true);
        $cpu->setLongMode(false);
        $cpu->setCompatibilityMode(false);
        $cpu->setProtectedMode(true);
        $cpu->setPagingEnabled(false);
        $cpu->setUserMode(false);
        $cpu->setCpl(0);
        $cpu->setDefaultOperandSize(32);
        $cpu->setDefaultAddressSize(32);

        $ma = $this->runtime->memoryAccessor();
        $cr0 = $ma->readControlRegister(0);
        $cr4 = $ma->readControlRegister(4);
        $ma->writeControlRegister(4, $cr4 & ~(1 << 5));
        $cr0 = ($cr0 | 0x33) & ~0x80000000;
        $ma->writeControlRegister(0, $cr0);
        $efer = $ma->readEfer();
        $ma->writeEfer($efer & ~((1 << 8) | (1 << 10)));
        $ma->setInterruptFlag(false);
    }

    private function enableLongMode(): void
    {
        $cpu = $this->runtime->context()->cpu();
        $cpu->enableA20(true);
        $cpu->setProtectedMode(true);
        $cpu->setLongMode(true);
        $cpu->setCompatibilityMode(false);
        $cpu->setPagingEnabled(true);
        $cpu->setUserMode(false);
        $cpu->setCpl(0);

        $ma = $this->runtime->memoryAccessor();
        $ma->writeControlRegister(3, self::PAGE_TABLE_BASE);
        $ma->writeControlRegister(4, (1 << 5) | (1 << 9) | (1 << 10));
        $ma->writeControlRegister(0, 0x80000033);
        $ma->writeEfer((1 << 8) | (1 << 10));
        $ma->setInterruptFlag(false);
    }

    private function setupSegments(): void
    {
        $ma = $this->runtime->memoryAccessor();
        $ma->write16Bit(RegisterType::CS, 0x08);
        $ma->write16Bit(RegisterType::DS, 0x10);
        $ma->write16Bit(RegisterType::ES, 0x10);
        $ma->write16Bit(RegisterType::SS, 0x10);
        $ma->write16Bit(RegisterType::FS, 0x10);
        $ma->write16Bit(RegisterType::GS, 0x10);
    }

    private function setupStack(UEFIEnvironmentInterface $env, int $stackAddrSize): int
    {
        $stackSize = 0x20000;
        $stackBase = $env->allocateStack($stackSize);
        $stackTop = ($stackBase + $stackSize) & ~0xF;
        $ma = $this->runtime->memoryAccessor();

        if ($stackAddrSize === 64) {
            $rsp = $stackTop - 0x28;
            $ma->writeBySize(RegisterType::ESP, $rsp, 64);
            $ma->writeBySize($rsp, 0, 64);
        } else {
            $ma->writeBySize(RegisterType::ESP, $stackTop, 32);
        }

        return $stackTop;
    }
}
