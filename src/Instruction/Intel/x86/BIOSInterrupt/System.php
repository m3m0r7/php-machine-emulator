<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class System implements InterruptInterface
{
    private array $e820Entries = [];

    public function process(RuntimeInterface $runtime): void
    {
        $ah = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asHighBit();

        match ($ah) {
            0x88 => $this->getExtendedMemorySize($runtime),
            0xC0 => $this->getSystemConfiguration($runtime),
            0xE8 => $this->memoryE820($runtime),
            0x24 => $this->a20($runtime),
            0x86 => $this->wait($runtime),
            0x87 => $this->moveExtendedMemory($runtime),
            default => $this->unsupported($runtime, $ah),
        };
    }

    private function a20(RuntimeInterface $runtime): void
    {
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
        $ma = $runtime->memoryAccessor();

        if ($al === 0x01) {
            $runtime->context()->cpu()->enableA20(true);
        }

        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->writeToLowBit(RegisterType::EAX, $runtime->context()->cpu()->isA20Enabled() ? 1 : 0);
        $ma->setCarryFlag(false);
    }

    /**
     * INT 15h AH=88h - Get Extended Memory Size (in KB above 1MB)
     */
    private function getExtendedMemorySize(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        // Return ~63MB of extended memory (max for this function is 64MB - 1KB)
        // This is capped at 0xFFFF (65535 KB)
        $ma->writeBySize(RegisterType::EAX, 0xFC00, 16); // ~63MB
        $ma->setCarryFlag(false);
    }

    /**
     * INT 15h AH=C0h - Get System Configuration Parameters
     */
    private function getSystemConfiguration(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        // Build system configuration table in low memory
        $tableAddr = 0x0000F000; // ROM BIOS area

        // Table structure:
        // Word: table length (in bytes, not including this word)
        // Byte: model byte
        // Byte: submodel byte
        // Byte: BIOS revision level
        // Byte: feature info byte 1
        // Byte: feature info byte 2
        // Byte: feature info byte 3
        // Byte: feature info byte 4
        // Byte: feature info byte 5

        $table = [
            0x08, 0x00,  // Length: 8 bytes
            0xFC,        // Model: AT compatible
            0x01,        // Submodel
            0x00,        // BIOS revision
            0x74,        // Feature 1: dual 8259, RTC, keyboard intercept
            0x00,        // Feature 2
            0x00,        // Feature 3
            0x00,        // Feature 4
            0x00,        // Feature 5
        ];

        for ($i = 0; $i < count($table); $i++) {
            $ma->allocate($tableAddr + $i, safe: false);
            $ma->writeBySize($tableAddr + $i, $table[$i], 8);
        }

        // Return ES:BX pointing to the table
        $ma->write16Bit(RegisterType::ES, ($tableAddr >> 4) & 0xF000);
        $ma->write16Bit(RegisterType::EBX, $tableAddr & 0xFFFF);
        $ma->writeToHighBit(RegisterType::EAX, 0x00); // AH = 0 (success)
        $ma->setCarryFlag(false);
    }

    /**
     * INT 15h AH=86h - Wait (microseconds in CX:DX)
     */
    private function wait(RuntimeInterface $runtime): void
    {
        // In emulation, we just return immediately (no real delay)
        $ma = $runtime->memoryAccessor();
        $ma->setCarryFlag(false);
    }

    /**
     * INT 15h AH=87h - Move Extended Memory Block
     */
    private function moveExtendedMemory(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();

        $cx = $ma->fetch(RegisterType::ECX)->asBytesBySize(16);
        $es = $ma->fetch(RegisterType::ES)->asByte();
        $si = $ma->fetch(RegisterType::ESI)->asBytesBySize(16);

        // GDT pointer is at ES:SI
        $gdtAddr = ($es << 4) + $si;

        // Read source and destination descriptors from GDT
        // Entry 2 (offset 0x10): source descriptor
        // Entry 3 (offset 0x18): destination descriptor
        $srcBase = $this->readDescriptorBase($runtime, $gdtAddr + 0x10);
        $dstBase = $this->readDescriptorBase($runtime, $gdtAddr + 0x18);

        // CX contains word count (so byte count = CX * 2)
        $byteCount = $cx * 2;

        // Perform the memory move
        for ($i = 0; $i < $byteCount; $i++) {
            $byte = $ma->tryToFetch($srcBase + $i)?->asHighBit() ?? 0;
            $ma->allocate($dstBase + $i, safe: false);
            $ma->writeBySize($dstBase + $i, $byte, 8);
        }

        $ma->writeToHighBit(RegisterType::EAX, 0x00); // Success
        $ma->setCarryFlag(false);
    }

    private function readDescriptorBase(RuntimeInterface $runtime, int $addr): int
    {
        $ma = $runtime->memoryAccessor();
        $baseLow = ($ma->tryToFetch($addr + 2)?->asHighBit() ?? 0) |
                   (($ma->tryToFetch($addr + 3)?->asHighBit() ?? 0) << 8);
        $baseMid = $ma->tryToFetch($addr + 4)?->asHighBit() ?? 0;
        $baseHigh = $ma->tryToFetch($addr + 7)?->asHighBit() ?? 0;

        return $baseLow | ($baseMid << 16) | ($baseHigh << 24);
    }

    private function memoryE820(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        $ebx = $ma->fetch(RegisterType::EBX)->asBytesBySize(32);
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);

        // Require "SMAP" signature
        if ($edx !== 0x534D4150) {
            $this->fail($runtime, 0x86);
            return;
        }

        if (empty($this->e820Entries)) {
            // Build a memory map that matches the emulator's configured RAM size.
            $maxMemory = $runtime->logicBoard()->memory()->maxMemory();
            $extendedBase = 0x00100000;
            $extendedLength = $maxMemory > $extendedBase ? $maxMemory - $extendedBase : 0;

            $this->e820Entries = [
                // Low memory (0 - 640KB) - usable
                [
                    'base' => 0x00000000,
                    'length' => 0x0009FC00, // 640KB - 1KB for EBDA
                    'type' => 1, // usable
                    'attr' => 1,
                ],
                // EBDA (Extended BIOS Data Area)
                [
                    'base' => 0x0009FC00,
                    'length' => 0x00000400, // 1KB
                    'type' => 2, // reserved
                    'attr' => 0,
                ],
                // Video memory & ROM area (640KB - 1MB)
                [
                    'base' => 0x000A0000,
                    'length' => 0x00060000, // 384KB
                    'type' => 2, // reserved
                    'attr' => 0,
                ],
            ];

            if ($extendedLength > 0) {
                $this->e820Entries[] = [
                    'base' => $extendedBase,
                    'length' => $extendedLength,
                    'type' => 1, // usable
                    'attr' => 1,
                ];
            }
        }

        $index = $ebx;
        if (!isset($this->e820Entries[$index])) {
            $ma->writeToHighBit(RegisterType::EAX, 0x00);
            $ma->writeBySize(RegisterType::EBX, 0, 32); // end
            $ma->setCarryFlag(false);
            return;
        }

        $es = $ma->fetch(RegisterType::ES)->asByte();
        $di = $ma->fetch(RegisterType::EDI)->asBytesBySize($addressSize) & $offsetMask;
        $buffer = ($es << 4) + $di;

        $entry = $this->e820Entries[$index];
        $fields = [
            $entry['base'] & 0xFFFFFFFF,
            ($entry['base'] >> 32) & 0xFFFFFFFF,
            $entry['length'] & 0xFFFFFFFF,
            ($entry['length'] >> 32) & 0xFFFFFFFF,
            $entry['type'],
            $entry['attr'],
        ];

        $bytesToWrite = min($ecx, 20);
        $p = $buffer;
        foreach ($fields as $field) {
            for ($i = 0; $i < 4 && $bytesToWrite > 0; $i++, $p++, $bytesToWrite--) {
                $ma->allocate($p, safe: false);
                $ma->writeBySize($p, ($field >> ($i * 8)) & 0xFF, 8);
            }
        }

        $ma->writeBySize(RegisterType::EAX, 0x534D4150, 32); // Return 'SMAP' signature
        $ma->writeBySize(RegisterType::EBX, $index + 1, 32); // next entry index
        $ma->writeBySize(RegisterType::ECX, min(20, $ecx), 32);
        // Note: Do NOT overwrite EAX here - SMAP signature must be returned
        $ma->setCarryFlag(false);
    }

    private function unsupported(RuntimeInterface $runtime, int $ah): void
    {
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, 0x86);
        $runtime->memoryAccessor()->setCarryFlag(true);
        $runtime->option()->logger()->warning(sprintf('INT 15h function AH=0x%02X not implemented', $ah));
    }

    private function fail(RuntimeInterface $runtime, int $errorCode): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->writeToHighBit(RegisterType::EAX, $errorCode);
        $ma->setCarryFlag(true);
    }
}
