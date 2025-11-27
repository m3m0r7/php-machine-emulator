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
            0xE8 => $this->memoryE820($runtime),
            0x24 => $this->a20($runtime),
            default => $this->unsupported($runtime, $ah),
        };
    }

    private function a20(RuntimeInterface $runtime): void
    {
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

        if ($al === 0x01) {
            $runtime->runtimeOption()->context()->enableA20(true);
        }

        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->writeToLowBit(RegisterType::EAX, $runtime->runtimeOption()->context()->isA20Enabled() ? 1 : 0);
        $ma->setCarryFlag(false);
    }

    private function memoryE820(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $addressSize = $runtime->runtimeOption()->context()->addressSize();
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
            $this->e820Entries = [
                [
                    'base' => 0x00000000,
                    'length' => 0x40000000, // 1GB usable
                    'type' => 1,
                    'attr' => 1,
                ],
                [
                    'base' => 0x40000000,
                    'length' => 0x01000000, // 16MB reserved
                    'type' => 2, // reserved
                    'attr' => 0,
                ],
            ];
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

        $ma->writeBySize(RegisterType::EAX, 0x534D4150, 32);
        $ma->writeBySize(RegisterType::EBX, $index + 1, 32); // next entry index
        $ma->writeBySize(RegisterType::ECX, min(20, $ecx), 32);
        $ma->writeToHighBit(RegisterType::EAX, 0x00);
        $ma->setCarryFlag(false);
    }

    private function unsupported(RuntimeInterface $runtime, int $ah): void
    {
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeToHighBit(RegisterType::EAX, 0x86);
        $runtime->memoryAccessor()->setCarryFlag(true);
        $runtime->option()->logger()->warning(sprintf('INT 15h function AH=0x%02X not implemented', $ah));
    }
}
