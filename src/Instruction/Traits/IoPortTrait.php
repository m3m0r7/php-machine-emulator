<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Ata;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for I/O port operations.
 * Handles IN/OUT instructions and various peripheral emulation.
 * Used by both x86 and x86_64 instructions.
 */
trait IoPortTrait
{
    /**
     * Assert I/O permission for the given port.
     *
     * Intel SDM specifies the following order for I/O permission checks:
     * 1. If CPL <= IOPL, the I/O operation is allowed (no bitmap check)
     * 2. If CPL > IOPL, check the I/O permission bitmap in TSS
     */
    protected function assertIoPermission(RuntimeInterface $runtime, int $port, int $width): void
    {
        if (!$runtime->context()->cpu()->isProtectedMode()) {
            return;
        }

        // First check IOPL - if CPL <= IOPL, I/O is always allowed
        $cpl = $runtime->context()->cpu()->cpl();
        $iopl = $runtime->context()->cpu()->iopl();
        if ($cpl <= $iopl) {
            return; // Privileged I/O access allowed
        }

        // CPL > IOPL: must check I/O permission bitmap in TSS
        $tr = $runtime->context()->cpu()->taskRegister();
        $trSelector = $tr['selector'] ?? 0;
        $trBase = $tr['base'] ?? 0;
        $trLimit = $tr['limit'] ?? 0;

        if ($trSelector === 0) {
            return; // no TSS loaded; allow
        }

        $tss32 = $this->tss32Offsets();
        // IO map base is word at TSS32 offset (iomap).
        $ioBase = $this->readMemory16($runtime, $trBase + $tss32['iomap']);
        if ($ioBase > $trLimit) {
            return; // I/O bitmap beyond limit => all access allowed
        }

        $bytesNeeded = intdiv($width, 8);
        if ($bytesNeeded === 0) {
            $bytesNeeded = 1; // Minimum 1 byte for 8-bit access
        }
        for ($i = 0; $i < $bytesNeeded; $i++) {
            $p = $port + $i;
            $byteOffset = $ioBase + intdiv($p, 8);
            // If bitmap extends beyond limit, #GP(0)
            if ($byteOffset > $trLimit) {
                throw new FaultException(0x0D, 0, sprintf('I/O port 0x%04X not permitted (bitmap beyond TSS)', $p));
            }
            $bit = $p & 0x7;
            $mask = 1 << $bit;
            $mapByte = $this->readMemory8($runtime, $trBase + $byteOffset);
            if (($mapByte & $mask) !== 0) {
                throw new FaultException(0x0D, 0, sprintf('I/O port 0x%04X not permitted', $p));
            }
        }
    }

    /**
     * Read from I/O port.
     */
    protected function readPort(RuntimeInterface $runtime, int $port, int $width): int
    {
        $runtime->option()->logger()->debug(sprintf('IN from port 0x%04X (%d-bit)', $port, $width));

        // Use function-local static variables to maintain state across calls
        // without causing issues between different test runs
        static $ata;
        $ata ??= new Ata($runtime);
        $ctx = $runtime->context()->cpu();
        $kbd = $ctx->keyboardController();
        $cmos = $ctx->cmos();
        $picState = $ctx->picState();
        static $pciConfigAddr = 0;
        static $pciConfigSpace = null;
        $pciConfigSpace ??= $this->defaultPciConfig();
        static $vga = null;
        $vga ??= [
            'seq_idx' => 0,
            'seq' => array_fill(0, 5, 0),
            'gfx_idx' => 0,
            'gfx' => array_fill(0, 9, 0),
            'crtc_idx' => 0,
            'crtc' => array_fill(0, 0x19, 0),
            'attr_idx' => 0,
            'attr' => array_fill(0, 0x15, 0),
            'misc_output' => 0x63,
            'feature' => 0,
            'flip_flop' => false,
        ];

        if ($port === 0x60) {
            return $kbd->readData($runtime);
        }

        if ($port === 0x64) {
            return $kbd->pollAndReadStatus($runtime);
        }

        if ($port === 0x1F0 || $port === 0x170) {
            return $ata->readDataWord();
        }
        if ($port === 0x1F7 || $port === 0x177 || $port === 0x3F6 || $port === 0x376) {
            return $ata->readStatus();
        }
        if (($port >= 0x1F2 && $port <= 0x1F6) || ($port >= 0x172 && $port <= 0x176)) {
            return $ata->readRegister($port);
        }
        if ($port >= 0xCC00 && $port <= 0xCC07) {
            return $ata->readBusMaster($port);
        }

        if ($port === 0x21) {
            return $picState->imrMaster;
        }
        if ($port === 0xA1) {
            return $picState->imrSlave;
        }

        if (in_array($port, [0x40, 0x41, 0x42, 0x43], true)) {
            return Pit::shared()->readCounter();
        }

        if ($port === 0x20) {
            return $picState->readCommandPort(false);
        }
        if ($port === 0xA0) {
            return $picState->readCommandPort(true);
        }

        if ($port === 0x92) {
            return $runtime->context()->cpu()->isA20Enabled() ? 0x02 : 0x00;
        }

        if ($port === 0xCF8) {
            return $pciConfigAddr;
        }
        if ($port === 0xCFC) {
            $bus = ($pciConfigAddr >> 16) & 0xFF;
            $dev = ($pciConfigAddr >> 11) & 0x1F;
            $func = ($pciConfigAddr >> 8) & 0x7;
            $reg = $pciConfigAddr & 0xFC;
            return $this->readPciConfig($pciConfigSpace, $bus, $dev, $func, $reg);
        }

        // VGA read stubs
        if ($port === 0x3C2 || $port === 0x3CC) {
            return $vga['misc_output'];
        }
        if ($port === 0x3C4) {
            return $vga['seq_idx'];
        }
        if ($port === 0x3C5) {
            return $vga['seq'][$vga['seq_idx']] ?? 0;
        }
        if ($port === 0x3CE) {
            return $vga['gfx_idx'];
        }
        if ($port === 0x3CF) {
            return $vga['gfx'][$vga['gfx_idx']] ?? 0;
        }
        if ($port === 0x3D4 || $port === 0x3B4) {
            return $vga['crtc_idx'];
        }
        if ($port === 0x3D5 || $port === 0x3B5) {
            return $vga['crtc'][$vga['crtc_idx']] ?? 0;
        }
        if ($port === 0x3C0) {
            $vga['flip_flop'] = !$vga['flip_flop'];
            return 0;
        }
        if ($port === 0x3C1) {
            return $vga['attr'][$vga['attr_idx']] ?? 0;
        }
        if ($port === 0x3DA) {
            $vga['flip_flop'] = false;
            return 0x09;
        }

        if (in_array($port, [0x70, 0x71], true)) {
            return $port === 0x71 ? $cmos->read() : 0;
        }

        if ($port === 0x3F8) {
            return 0;
        }

        return 0;
    }

    /**
     * Write to I/O port.
     */
    protected function writePort(RuntimeInterface $runtime, int $port, int $value, int $width): void
    {
        $mask = $width === 8 ? 0xFF : ($width === 16 ? 0xFFFF : 0xFFFFFFFF);
        $value &= $mask;
        $runtime->option()->logger()->debug(sprintf('OUT to port 0x%04X value 0x%X (%d-bit)', $port, $value, $width));

        $ctx = $runtime->context()->cpu();
        $picState = $ctx->picState();
        static $ata;
        $ata ??= new Ata($runtime);
        $pit = Pit::shared();
        $kbd = $ctx->keyboardController();
        $cmos = $ctx->cmos();
        static $pciConfigAddr = 0;
        static $pciConfigSpace = null;
        $pciConfigSpace ??= $this->defaultPciConfig();
        static $vga = null;
        $vga ??= [
            'seq_idx' => 0,
            'seq' => array_fill(0, 5, 0),
            'gfx_idx' => 0,
            'gfx' => array_fill(0, 9, 0),
            'crtc_idx' => 0,
            'crtc' => array_fill(0, 0x19, 0),
            'attr_idx' => 0,
            'attr' => array_fill(0, 0x15, 0),
            'misc_output' => 0x63,
            'feature' => 0,
            'flip_flop' => false,
        ];

        if ($port === 0x3F8) {
            $runtime->option()->IO()->output()->write(chr($value & 0xFF));
            return;
        }

        if ($port === 0x92) {
            $runtime->context()->cpu()->enableA20(($value & 0x02) !== 0);
            return;
        }

        if ($port === 0xCF8) {
            $pciConfigAddr = $value;
            return;
        }
        if ($port === 0xCFC) {
            $bus = ($pciConfigAddr >> 16) & 0xFF;
            $dev = ($pciConfigAddr >> 11) & 0x1F;
            $func = ($pciConfigAddr >> 8) & 0x7;
            $reg = $pciConfigAddr & 0xFC;
            $this->writePciConfig($pciConfigSpace, $bus, $dev, $func, $reg, $value, $width);
            return;
        }

        if ($port === 0x1F0 || $port === 0x170) {
            $ata->writeDataWord($value);
            return;
        }
        if (($port >= 0x1F1 && $port <= 0x1F7) || ($port >= 0x171 && $port <= 0x177)) {
            $ata->writeRegister($port, $value);
            return;
        }
        if ($port >= 0xCC00 && $port <= 0xCC07) {
            $ata->writeBusMaster($port, $value);
            return;
        }

        // VGA writes
        if ($port === 0x3C2) {
            $vga['misc_output'] = $value & 0xFF;
            return;
        }
        if ($port === 0x3C4) {
            $vga['seq_idx'] = $value & 0x1F;
            return;
        }
        if ($port === 0x3C5) {
            $vga['seq'][$vga['seq_idx']] = $value & 0xFF;
            return;
        }
        if ($port === 0x3CE) {
            $vga['gfx_idx'] = $value & 0x1F;
            return;
        }
        if ($port === 0x3CF) {
            $vga['gfx'][$vga['gfx_idx']] = $value & 0xFF;
            return;
        }
        if ($port === 0x3D4 || $port === 0x3B4) {
            $vga['crtc_idx'] = $value & 0x3F;
            return;
        }
        if ($port === 0x3D5 || $port === 0x3B5) {
            $vga['crtc'][$vga['crtc_idx']] = $value & 0xFF;
            return;
        }
        if ($port === 0x3C0) {
            if ($vga['flip_flop'] === false) {
                $vga['attr_idx'] = $value & 0x1F;
            } else {
                $vga['attr'][$vga['attr_idx']] = $value & 0xFF;
            }
            $vga['flip_flop'] = !$vga['flip_flop'];
            return;
        }
        if ($port === 0x3C3) {
            $vga['feature'] = $value & 0xFF;
            return;
        }
        if ($port === 0x3DA) {
            $vga['flip_flop'] = false;
            return;
        }

        if ($port === 0x20) {
            $picState->writeCommandMaster($value & 0xFF);
            return;
        }
        if ($port === 0xA0) {
            $picState->writeCommandSlave($value & 0xFF);
            return;
        }
        if ($port === 0x21) {
            $picState->writeDataMaster($value & 0xFF);
            return;
        }
        if ($port === 0xA1) {
            $picState->writeDataSlave($value & 0xFF);
            return;
        }

        if (in_array($port, [0x40, 0x41, 0x42], true)) {
            $pit->writeChannel($port - 0x40, $value & 0xFF);
            return;
        }
        if ($port === 0x43) {
            $pit->writeControl($value & 0xFF);
            return;
        }

        if ($port === 0x64) {
            $kbd->writeCommand($value, $runtime);
            return;
        }
        if ($port === 0x60) {
            $kbd->writeDataPort($value, $runtime);
            return;
        }

        if ($port === 0x70) {
            $cmos->writeIndex($value & 0xFF);
            return;
        }

        if (in_array($port, [0x20, 0x21, 0xA0, 0xA1], true)) {
            return;
        }

        if (in_array($port, [0x70, 0x71], true)) {
            return;
        }
    }

    /**
     * Default PCI configuration space.
     */
    private function defaultPciConfig(): array
    {
        return [
            '0:0:0' => [
                0x00 => 0x12378086, // device/vendor
                0x04 => 0x00000000, // command/status
                0x08 => 0x00060000, // class code host bridge
                0x3C => 0x00000000, // interrupt line/pin
            ],
            '0:1f:0' => [
                0x00 => 0x70008086, // ISA bridge
                0x04 => 0x00000000,
                0x08 => 0x00060100,
                0x3C => 0x00000000,
            ],
            '0:1f:1' => [
                0x00 => 0x70108086, // IDE
                0x04 => 0x00000000,
                0x08 => 0x00010180, // IDE controller, legacy mode
                0x10 => 0x000001F0, // BAR0 legacy
                0x14 => 0x000003F4, // BAR1
                0x18 => 0x00000170, // BAR2
                0x1C => 0x00000374, // BAR3
                0x20 => 0x0000CC00, // BAR4 (bus master IDE, dummy)
                0x3C => 0x00000E01, // interrupt line 14, pin INTA
            ],
            '0:2:0' => [
                0x00 => 0x11111234, // VGA vendor/device (bochs-like)
                0x04 => 0x00000000,
                0x08 => 0x00030000, // VGA display controller
                0x10 => 0xE0000000, // BAR0 prefetchable framebuffer (dummy)
                0x14 => 0x00000000, // BAR1
                0x3C => 0x00000000, // interrupt line/pin
            ],
        ];
    }

    /**
     * Read PCI configuration register.
     */
    private function readPciConfig(array $space, int $bus, int $dev, int $func, int $reg): int
    {
        $key = sprintf('%d:%d:%d', $bus, $dev, $func);
        $table = $space[$key] ?? null;
        if ($table === null) {
            return 0xFFFFFFFF;
        }

        $regAligned = $reg & 0xFC;
        return $table[$regAligned] ?? 0xFFFFFFFF;
    }

    /**
     * Write PCI configuration register.
     */
    private function writePciConfig(array &$space, int $bus, int $dev, int $func, int $reg, int $value, int $width): void
    {
        $key = sprintf('%d:%d:%d', $bus, $dev, $func);
        if (!isset($space[$key])) {
            return;
        }
        $regAligned = $reg & 0xFC;
        $current = $space[$key][$regAligned] ?? 0;
        $shift = ($reg & 0x3) * 8;
        $mask = match ($width) {
            8 => 0xFF << $shift,
            16 => 0xFFFF << $shift,
            default => 0xFFFFFFFF,
        };
        $newVal = ($current & ~$mask) | (($value << $shift) & $mask);
        // keep device/vendor readonly
        if ($regAligned === 0x00) {
            return;
        }
        $space[$key][$regAligned] = $newVal & 0xFFFFFFFF;
    }

    // Abstract methods that must be implemented by using class/trait
    abstract protected function tss32Offsets(): array;
    abstract protected function readMemory8(RuntimeInterface $runtime, int $address): int;
    abstract protected function readMemory16(RuntimeInterface $runtime, int $address): int;
}
