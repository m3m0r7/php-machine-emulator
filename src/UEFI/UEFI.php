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
use PHPMachineEmulator\Stream\ISO\ISOBootImageStream;

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

        $iso = $this->isoFromMedia();
        [$efiPath, $efiImage] = $this->selectEfiImage($iso);

        $loader = new PELoader();
        $loaded = $loader->load($this->runtime, $efiImage);

        $env = new UEFIEnvironment(
            $this->runtime,
            $iso,
            $loaded['base'],
            $loaded['size'],
            $efiPath,
        );
        $systemTable = $env->build();
        UEFIRuntimeRegistry::register($this->runtime, $env->dispatcher());

        $this->setupPageTables();
        $this->setupGdt();
        $this->enableLongMode();
        $this->setupSegments();
        $this->setupStack($env);

        $ma = $this->runtime->memoryAccessor();
        $ma->writeBySize(RegisterType::ECX, $env->imageHandle(), 64);
        $ma->writeBySize(RegisterType::EDX, $systemTable, 64);

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
     * @return array{string,string}
     */
    private function selectEfiImage(\PHPMachineEmulator\Stream\ISO\ISO9660 $iso): array
    {
        $paths = [
            '/EFI/BOOT/GRUBX64.EFI',
            '/EFI/BOOT/BOOTX64.EFI',
        ];

        foreach ($paths as $path) {
            $data = $iso->readFile($path);
            if ($data !== null) {
                return [$path, $data];
            }
        }

        throw new StreamReaderException('EFI bootloader not found in ISO');
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
        $base = self::PAGE_TABLE_BASE;
        $pml4 = $base;
        $pdpt = $base + 0x1000;
        $pd = $base + 0x2000;

        $ma->allocate($base, 0x3000, safe: false);
        for ($i = 0; $i < 0x3000; $i += 8) {
            $ma->writePhysical64($base + $i, 0);
        }

        $ma->writePhysical64($pml4, $pdpt | 0x003);
        $ma->writePhysical64($pdpt, $pd | 0x003);

        for ($i = 0; $i < 512; $i++) {
            $entry = ($i * 0x200000) | 0x083;
            $ma->writePhysical64($pd + ($i * 8), $entry);
        }
    }

    private function setupGdt(): void
    {
        $ma = $this->runtime->memoryAccessor();
        $base = self::GDT_BASE;
        $ma->allocate($base, 24, safe: false);
        $ma->writePhysical64($base, 0x0000000000000000);
        $ma->writePhysical64($base + 8, 0x00AF9A000000FFFF);
        $ma->writePhysical64($base + 16, 0x00CF92000000FFFF);

        $this->runtime->context()->cpu()->setGdtr($base, 24 - 1);
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
        $ma->writeControlRegister(0, 0x80000001);
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

    private function setupStack(UEFIEnvironment $env): void
    {
        $stackSize = 0x20000;
        $stackBase = $env->allocateStack($stackSize);
        $stackTop = ($stackBase + $stackSize) & ~0xF;
        $rsp = $stackTop - 8;

        $this->runtime->memoryAccessor()->writeBySize(RegisterType::ESP, $rsp, 64);
        $this->runtime->memoryAccessor()->writeBySize($rsp, 0, 64);
    }
}
