<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Ata;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Ata as AtaPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Cmos as CmosPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Kbc as KbcPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Pci as PciPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Pic as PicPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Pit as PitPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Serial as SerialPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\SystemControl as SystemControlPort;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga\Attribute as VgaAttribute;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga\Crtc as VgaCrtc;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga\General as VgaGeneral;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga\Graphics as VgaGraphics;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Vga\Sequencer as VgaSequencer;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for I/O port operations.
 * Handles IN/OUT instructions and various peripheral emulation.
 * Used by both x86 and x86_64 instructions.
 */
trait IoPortTrait
{
    private ?Ata $ioPortAta = null;
    private int $ioPortPciConfigAddr = 0;
    /** @var array<string, array<int, int>>|null */
    private ?array $ioPortPciConfigSpace = null;
    /** @var array<string, mixed>|null */
    private ?array $ioPortVgaState = null;

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

        $ata = $this->ioPortAta ??= Ata::forRuntime($runtime);
        $ctx = $runtime->context()->cpu();
        $kbd = $ctx->keyboardController();
        $cmos = $ctx->cmos();
        $picState = $ctx->picState();
        if ($this->ioPortPciConfigSpace === null) {
            $this->ioPortPciConfigSpace = $this->defaultPciConfig();
        }
        if ($this->ioPortVgaState === null) {
            $this->ioPortVgaState = [
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
        }
        $vga = &$this->ioPortVgaState;

        // Keyboard Controller
        if ($port === KbcPort::DATA->value) {
            return $kbd->readData($runtime);
        }
        if ($port === KbcPort::STATUS_COMMAND->value) {
            return $kbd->pollAndReadStatus($runtime);
        }

        // ATA/IDE
        if (AtaPort::isDataPort($port)) {
            if ($width === 32) {
                $lo = $ata->readDataWord($port);
                $hi = $ata->readDataWord($port);
                return (($hi & 0xFFFF) << 16) | ($lo & 0xFFFF);
            }
            if ($width === 16) {
                return $ata->readDataWord($port);
            }
            // Match QEMU: 8-bit reads consume a word and return the low byte.
            return $ata->readDataWord($port) & 0xFF;
        }
        if (AtaPort::isStatusPort($port)) {
            return $ata->readStatus($port);
        }
        if (AtaPort::isRegisterPort($port)) {
            return $ata->readRegister($port);
        }
        if (AtaPort::isBusMasterPort($port)) {
            return $ata->readBusMaster($port);
        }

        // PIC
        if ($port === PicPort::MASTER_DATA->value) {
            return $picState->imrMaster;
        }
        if ($port === PicPort::SLAVE_DATA->value) {
            return $picState->imrSlave;
        }
        if ($port === PicPort::MASTER_COMMAND->value) {
            return $picState->readCommandPort(false);
        }
        if ($port === PicPort::SLAVE_COMMAND->value) {
            return $picState->readCommandPort(true);
        }

        // PIT
        if (PitPort::isPort($port)) {
            return $ctx->pit()->readCounter();
        }

        // System Control (A20 gate)
        if ($port === SystemControlPort::PORT_A->value) {
            return $runtime->context()->cpu()->isA20Enabled() ? 0x02 : 0x00;
        }

        // PCI Configuration
        if ($port === PciPort::CONFIG_ADDRESS->value) {
            return $this->ioPortPciConfigAddr;
        }
        if ($port === PciPort::CONFIG_DATA->value) {
            $bus = ($this->ioPortPciConfigAddr >> 16) & 0xFF;
            $dev = ($this->ioPortPciConfigAddr >> 11) & 0x1F;
            $func = ($this->ioPortPciConfigAddr >> 8) & 0x7;
            $reg = $this->ioPortPciConfigAddr & 0xFC;
            return $this->readPciConfig($this->ioPortPciConfigSpace, $bus, $dev, $func, $reg);
        }

        // VGA read stubs
        if ($port === VgaGeneral::INPUT_STATUS_0 || $port === VgaGeneral::MISC_OUTPUT_READ) {
            return $vga['misc_output'];
        }
        if ($port === VgaSequencer::INDEX->value) {
            return $vga['seq_idx'];
        }
        if ($port === VgaSequencer::DATA->value) {
            return $vga['seq'][$vga['seq_idx']] ?? 0;
        }
        if ($port === VgaGraphics::INDEX->value) {
            return $vga['gfx_idx'];
        }
        if ($port === VgaGraphics::DATA->value) {
            return $vga['gfx'][$vga['gfx_idx']] ?? 0;
        }
        if ($port === VgaCrtc::INDEX_COLOR->value || $port === VgaCrtc::INDEX_MONO->value) {
            return $vga['crtc_idx'];
        }
        if ($port === VgaCrtc::DATA_COLOR->value || $port === VgaCrtc::DATA_MONO->value) {
            return $vga['crtc'][$vga['crtc_idx']] ?? 0;
        }
        if ($port === VgaAttribute::INDEX_DATA_WRITE->value) {
            $vga['flip_flop'] = !$vga['flip_flop'];
            return 0;
        }
        if ($port === VgaAttribute::DATA_READ->value) {
            return $vga['attr'][$vga['attr_idx']] ?? 0;
        }
        if ($port === VgaGeneral::INPUT_STATUS_1_COLOR) {
            $vga['flip_flop'] = false;
            return 0x09;
        }

        // CMOS/RTC
        if ($port === CmosPort::ADDRESS->value || $port === CmosPort::DATA->value) {
            return $port === CmosPort::DATA->value ? $cmos->read() : 0;
        }

        // Serial Port
        if ($port === SerialPort::COM1_DATA->value) {
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
        $pit = $ctx->pit();
        $ata = $this->ioPortAta ??= Ata::forRuntime($runtime);
        $kbd = $ctx->keyboardController();
        $cmos = $ctx->cmos();
        if ($this->ioPortPciConfigSpace === null) {
            $this->ioPortPciConfigSpace = $this->defaultPciConfig();
        }
        if ($this->ioPortVgaState === null) {
            $this->ioPortVgaState = [
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
        }
        $vga = &$this->ioPortVgaState;

        // Serial Port
        if ($port === SerialPort::COM1_DATA->value) {
            $runtime->option()->IO()->output()->write(chr($value & 0xFF));
            return;
        }

        // System Control (A20 gate)
        if ($port === SystemControlPort::PORT_A->value) {
            $runtime->context()->cpu()->enableA20(($value & 0x02) !== 0);
            return;
        }

        // PCI Configuration
        if ($port === PciPort::CONFIG_ADDRESS->value) {
            $this->ioPortPciConfigAddr = $value;
            return;
        }
        if ($port === PciPort::CONFIG_DATA->value) {
            $bus = ($this->ioPortPciConfigAddr >> 16) & 0xFF;
            $dev = ($this->ioPortPciConfigAddr >> 11) & 0x1F;
            $func = ($this->ioPortPciConfigAddr >> 8) & 0x7;
            $reg = $this->ioPortPciConfigAddr & 0xFC;
            $this->writePciConfig($this->ioPortPciConfigSpace, $bus, $dev, $func, $reg, $value, $width);
            return;
        }

        // ATA/IDE
        if (AtaPort::isDataPort($port)) {
            if ($width === 32) {
                $ata->writeDataWord($port, $value & 0xFFFF);
                $ata->writeDataWord($port, ($value >> 16) & 0xFFFF);
                return;
            }
            if ($width === 16) {
                $ata->writeDataWord($port, $value);
                return;
            }
            return;
        }
        if (AtaPort::isWritableRegisterPort($port)) {
            $ata->writeRegister($port, $value);
            return;
        }
        if (AtaPort::isBusMasterPort($port)) {
            $ata->writeBusMaster($port, $value);
            return;
        }

        // VGA writes
        if ($port === VgaGeneral::MISC_OUTPUT_WRITE) {
            $vga['misc_output'] = $value & 0xFF;
            return;
        }
        if ($port === VgaSequencer::INDEX->value) {
            $vga['seq_idx'] = $value & 0x1F;
            return;
        }
        if ($port === VgaSequencer::DATA->value) {
            $vga['seq'][$vga['seq_idx']] = $value & 0xFF;
            return;
        }
        if ($port === VgaGraphics::INDEX->value) {
            $vga['gfx_idx'] = $value & 0x1F;
            return;
        }
        if ($port === VgaGraphics::DATA->value) {
            $vga['gfx'][$vga['gfx_idx']] = $value & 0xFF;
            return;
        }
        if ($port === VgaCrtc::INDEX_COLOR->value || $port === VgaCrtc::INDEX_MONO->value) {
            $vga['crtc_idx'] = $value & 0x3F;
            return;
        }
        if ($port === VgaCrtc::DATA_COLOR->value || $port === VgaCrtc::DATA_MONO->value) {
            $vga['crtc'][$vga['crtc_idx']] = $value & 0xFF;
            return;
        }
        if ($port === VgaAttribute::INDEX_DATA_WRITE->value) {
            if ($vga['flip_flop'] === false) {
                $vga['attr_idx'] = $value & 0x1F;
            } else {
                $vga['attr'][$vga['attr_idx']] = $value & 0xFF;
            }
            $vga['flip_flop'] = !$vga['flip_flop'];
            return;
        }
        if ($port === VgaGeneral::VGA_ENABLE) {
            $vga['feature'] = $value & 0xFF;
            return;
        }
        if ($port === VgaGeneral::INPUT_STATUS_1_COLOR) {
            $vga['flip_flop'] = false;
            return;
        }

        // PIC
        if ($port === PicPort::MASTER_COMMAND->value) {
            $picState->writeCommandMaster($value & 0xFF);
            return;
        }
        if ($port === PicPort::SLAVE_COMMAND->value) {
            $picState->writeCommandSlave($value & 0xFF);
            return;
        }
        if ($port === PicPort::MASTER_DATA->value) {
            $picState->writeDataMaster($value & 0xFF);
            return;
        }
        if ($port === PicPort::SLAVE_DATA->value) {
            $picState->writeDataSlave($value & 0xFF);
            return;
        }

        // PIT
        if ($port >= PitPort::CHANNEL_0->value && $port <= PitPort::CHANNEL_2->value) {
            $pit->writeChannel(PitPort::channelFromPort($port), $value & 0xFF);
            return;
        }
        if ($port === PitPort::CONTROL->value) {
            $pit->writeControl($value & 0xFF);
            return;
        }

        // Keyboard Controller
        if ($port === KbcPort::STATUS_COMMAND->value) {
            $kbd->writeCommand($value, $runtime);
            return;
        }
        if ($port === KbcPort::DATA->value) {
            $kbd->writeDataPort($value, $runtime);
            return;
        }

        // CMOS/RTC
        if ($port === CmosPort::ADDRESS->value) {
            $cmos->writeIndex($value & 0xFF);
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
