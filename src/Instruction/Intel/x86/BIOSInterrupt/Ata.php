<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\BIOS;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Exception\StreamReaderException;

class Ata
{
    private int $sectorCount = 0;
    private int $lba0 = 0;
    private int $lba1 = 0;
    private int $lba2 = 0;
    private int $driveHead = 0;
    private array $buffer = [];
    private int $bufferPos = 0;
    private int $status = 0x50; // DRDY + DSC
    private int $error = 0x00;
    private int $bmCommand = 0x00;
    private int $bmStatus = 0x00;
    private int $bmPrdLow = 0x00;
    private int $bmPrdHigh = 0x00;
    private bool $bmActive = false;
    private bool $irqDisabled = false;
    private bool $srst = false;
    private bool $writeMode = false;
    private int $writeBufferPos = 0;
    private array $writeBuffer = [];

    public function __construct(private RuntimeInterface $runtime)
    {
    }

    public function writeRegister(int $port, int $value): void
    {
        $v = $value & 0xFF;
        switch ($port) {
            case 0x1F2:
                $this->sectorCount = $v;
                break;
            case 0x1F3:
                $this->lba0 = $v;
                break;
            case 0x1F4:
                $this->lba1 = $v;
                break;
            case 0x1F5:
                $this->lba2 = $v;
                break;
            case 0x1F6:
                $this->driveHead = $v;
                break;
            case 0x1F7:
                if ($v === 0x20 || $v === 0x21) { // READ SECTORS
                    $this->status = 0x80; // BSY
                    $this->loadBuffer();
                    if (empty($this->buffer)) {
                        $this->status = 0x41; // ERR | DRDY
                        $this->error = 0x04; // ABRT
                    } else {
                        $this->status = 0x58; // DRDY | DRQ | DSC
                        $this->raiseIrq();
                    }
                } elseif ($v === 0x30 || $v === 0x31) { // WRITE SECTORS
                    $this->status = 0x80; // BSY
                    $this->prepareWriteBuffer();
                    $this->status = 0x58; // DRDY | DRQ | DSC - ready for data
                } elseif ($v === 0xEC) { // IDENTIFY DEVICE
                    $this->status = 0x80;
                    $this->loadIdentify();
                    $this->status = 0x58; // DRDY | DRQ | DSC
                    // Fire IRQ14 to signal data ready
                    $this->raiseIrq();
                } elseif ($v === 0xE7) { // FLUSH CACHE
                    $this->status = 0x50; // ready, no-op
                } elseif ($v === 0xCA) { // WRITE DMA
                    $this->status = 0x80; // BSY
                    $this->prepareWriteBuffer();
                    $this->status = 0x58; // ready for DMA transfer
                } else {
                    $this->status = 0x50; // default ready + DSC
                }
                break;
            case 0x3F6: // device control
                $this->irqDisabled = ($v & 0x02) !== 0; // nIEN
                $this->srst = ($v & 0x04) !== 0;
                if ($this->srst) {
                    $this->resetDevice();
                }
                break;
            default:
                // BMIDE registers ignored here
                break;
        }
    }

    public function readRegister(int $port): int
    {
        return match ($port) {
            0x1F1 => $this->error,
            0x1F2 => $this->sectorCount,
            0x1F3 => $this->lba0,
            0x1F4 => $this->lba1,
            0x1F5 => $this->lba2,
            0x1F6 => $this->driveHead,
            default => 0,
        };
    }

    public function readStatus(): int
    {
        // BSY=0, DRDY=1, DRQ if data pending, ERR if buffer missing
        $drq = $this->bufferPos < count($this->buffer);
        $errBit = ($this->error !== 0) ? 0x01 : 0x00;
        $this->status = 0x50 | ($drq ? 0x08 : 0x00) | $errBit; // DRDY|DSC
        return $this->status;
    }

    public function readDataWord(): int
    {
        if ($this->bufferPos >= count($this->buffer)) {
            return 0;
        }
        $lo = $this->buffer[$this->bufferPos] ?? 0;
        $hi = $this->buffer[$this->bufferPos + 1] ?? 0;
        $this->bufferPos += 2;
        if ($this->bufferPos >= count($this->buffer)) {
            $this->status = 0x50; // DRDY + DSC
        }
        return ($hi << 8) | $lo;
    }

    public function writeDataWord(int $value): void
    {
        if (!$this->writeMode) {
            return;
        }

        $lo = $value & 0xFF;
        $hi = ($value >> 8) & 0xFF;
        $this->writeBuffer[$this->writeBufferPos++] = $lo;
        $this->writeBuffer[$this->writeBufferPos++] = $hi;

        $expectedSize = max(1, $this->sectorCount) * BIOS::READ_SIZE_PER_SECTOR;
        if ($this->writeBufferPos >= $expectedSize) {
            $this->flushWriteBuffer();
            $this->writeMode = false;
            $this->status = 0x50; // DRDY + DSC
            $this->raiseIrq();
        }
    }

    private function prepareWriteBuffer(): void
    {
        $this->writeMode = true;
        $this->writeBufferPos = 0;
        $this->writeBuffer = [];
        $this->error = 0;
    }

    private function flushWriteBuffer(): void
    {
        $lba = $this->lba0 | ($this->lba1 << 8) | ($this->lba2 << 16) | (($this->driveHead & 0x0F) << 24);
        $offset = $lba * BIOS::READ_SIZE_PER_SECTOR;

        // Write to the underlying stream if it supports writing
        $proxy = $this->runtime->streamReader()->proxy();
        if (method_exists($proxy, 'writeAt')) {
            $proxy->writeAt($offset, $this->writeBuffer);
        }
        // Note: For read-only disk images, this is a no-op
        // The data is still accepted to avoid errors, but not persisted
    }

    public function readBusMaster(int $port): int
    {
        $offset = $port & 0x7;
        return match ($offset) {
            0x0 => $this->bmCommand,
            0x2 => $this->bmStatus,
            0x4 => $this->bmPrdLow,
            0x5 => ($this->bmPrdLow >> 8) & 0xFF,
            0x6 => $this->bmPrdHigh,
            0x7 => ($this->bmPrdHigh >> 8) & 0xFF,
            default => 0,
        };
    }

    public function writeBusMaster(int $port, int $value): void
    {
        $offset = $port & 0x7;
        $v = $value & 0xFF;
        switch ($offset) {
            case 0x0:
                $this->bmCommand = $v;
                if (($v & 0x1) !== 0 && !$this->bmActive) {
                    $this->beginDma();
                } else {
                    $this->bmActive = false;
                    $this->bmStatus &= ~0x01;
                }
                break;
            case 0x2:
                // writing 1 clears interrupt/error bits
                if ($v & 0x02) {
                    $this->bmStatus &= ~0x02;
                }
                if ($v & 0x04) {
                    $this->bmStatus &= ~0x04;
                }
                break;
            case 0x4:
                $this->bmPrdLow = ($this->bmPrdLow & 0xFFFFFF00) | $v;
                break;
            case 0x5:
                $this->bmPrdLow = ($this->bmPrdLow & 0xFFFF00FF) | ($v << 8);
                break;
            case 0x6:
                $this->bmPrdHigh = ($this->bmPrdHigh & 0xFFFFFF00) | $v;
                break;
            case 0x7:
                $this->bmPrdHigh = ($this->bmPrdHigh & 0xFFFF00FF) | ($v << 8);
                break;
            default:
                break;
        }
    }

    private function beginDma(): void
    {
        $this->bmActive = true;
        $this->bmStatus |= 0x01; // active
        $this->bmStatus &= ~0x06; // clear err/int
        $this->status = 0x80; // BSY
        $this->loadBuffer();
        if (empty($this->buffer)) {
            $this->bmStatus &= ~0x01;
            $this->bmStatus |= 0x02 | 0x04; // error + interrupt
            $this->status = 0x41;
            $this->error = 0x04;
            $this->runtime->context()->cpu()->picState()->raiseIrq(14);
            return;
        }

        $prd = $this->bmPrdLow | ($this->bmPrdHigh << 16);
        $bufPos = 0;
        $bufLen = count($this->buffer);

        while (true) {
            $base = $this->readPhysical32($prd);
            $count = $this->readPhysical16($prd + 4);
            $flags = $this->readPhysical16($prd + 6);
            $transfer = $count === 0 ? 0x10000 : $count;

            for ($i = 0; $i < $transfer; $i++) {
                $byte = $bufPos < $bufLen ? $this->buffer[$bufPos] : 0;
                $this->writePhysical8($base + $i, $byte);
                $bufPos++;
                if ($bufPos >= $bufLen) {
                    break 2;
                }
            }
            $prd += 8;
            if (($flags & 0x8000) !== 0) {
                break;
            }
        }

        $this->bmActive = false;
        $this->bmStatus &= ~0x01;
        $this->bmStatus |= 0x04; // interrupt
        $this->status = 0x50; // DRDY + DSC
        $this->raiseIrq();
    }

    private function readPhysical8(int $addr): int
    {
        return $this->runtime->memoryAccessor()->tryToFetch($addr)?->asHighBit() ?? 0;
    }

    private function readPhysical16(int $addr): int
    {
        $lo = $this->readPhysical8($addr);
        $hi = $this->readPhysical8($addr + 1);
        return ($hi << 8) | $lo;
    }

    private function readPhysical32(int $addr): int
    {
        $b0 = $this->readPhysical8($addr);
        $b1 = $this->readPhysical8($addr + 1);
        $b2 = $this->readPhysical8($addr + 2);
        $b3 = $this->readPhysical8($addr + 3);
        return ($b3 << 24) | ($b2 << 16) | ($b1 << 8) | $b0;
    }

    private function writePhysical8(int $addr, int $value): void
    {
        $this->runtime->memoryAccessor()->allocate($addr, safe: false);
        $this->runtime->memoryAccessor()->writeBySize($addr, $value & 0xFF, 8);
    }

    private function loadBuffer(): void
    {
        $lba = $this->lba0 | ($this->lba1 << 8) | ($this->lba2 << 16) | (($this->driveHead & 0x0F) << 24);
        $sectors = max(1, $this->sectorCount);
        $bytesToRead = $sectors * BIOS::READ_SIZE_PER_SECTOR;
        $this->buffer = [];
        $this->bufferPos = 0;
        $this->error = 0;

        $proxy = $this->runtime->streamReader()->proxy();
        try {
            $proxy->setOffset($lba * BIOS::READ_SIZE_PER_SECTOR);
            for ($i = 0; $i < $bytesToRead; $i++) {
                $this->buffer[] = $proxy->byte();
            }
        } catch (StreamReaderException) {
            $this->buffer = [];
            $this->bufferPos = 0;
            $this->error = 0x04; // ABRT
        }
    }

    private function loadIdentify(): void
    {
        $this->buffer = array_fill(0, 512, 0);
        $this->bufferPos = 0;
        $this->error = 0;
        // Word 0: general config
        $this->buffer[0] = 0x40;
        $this->buffer[1] = 0x00;
        // Word 23-26: firmware revision "1.0 "
        $this->buffer[46] = ord('1');
        $this->buffer[47] = ord('.');
        $this->buffer[48] = ord('0');
        $this->buffer[49] = 0x20;
        // Word 27-46: model "PHP ATA DISK"
        $model = str_pad('PHP ATA DISK', 40, ' ');
        for ($i = 0; $i < 40; $i += 2) {
            $this->buffer[54 + $i] = ord($model[$i + 1]);
            $this->buffer[55 + $i] = ord($model[$i]);
        }
        // Word 60-61: total sectors (fake 8GB)
        $sectors = 0x01000000; // 16,777,216 sectors ~8GB
        $this->buffer[120] = $sectors & 0xFF;
        $this->buffer[121] = ($sectors >> 8) & 0xFF;
        $this->buffer[122] = ($sectors >> 16) & 0xFF;
        $this->buffer[123] = ($sectors >> 24) & 0xFF;
        // Capabilities (word 49)
        $this->buffer[98] = 0x0F;
        $this->buffer[99] = 0x00;
    }

    private function raiseIrq(): void
    {
        if ($this->irqDisabled) {
            return;
        }
        $this->runtime->context()->cpu()->picState()->raiseIrq(14);
    }

    private function resetDevice(): void
    {
        $this->sectorCount = 0;
        $this->lba0 = $this->lba1 = $this->lba2 = 0;
        $this->driveHead = 0;
        $this->buffer = [];
        $this->bufferPos = 0;
        $this->error = 0;
        $this->status = 0x50; // DRDY | DSC
        $this->bmCommand = $this->bmStatus = 0;
        $this->bmActive = false;
    }
}
