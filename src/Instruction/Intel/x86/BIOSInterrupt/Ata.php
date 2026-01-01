<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\Intel\x86\IoPort\Ata as AtaPort;
use PHPMachineEmulator\BootType;
use PHPMachineEmulator\LogicBoard\Media\DriveType;
use PHPMachineEmulator\LogicBoard\Media\MediaContext;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\ISO\ISOStreamProxy;

class Ata
{
    private const DEVICE_NONE = 'none';
    private const DEVICE_ATA = 'ata';
    private const DEVICE_ATAPI = 'atapi';
    private const ATAPI_PACKET_SIZE = 12;
    private const ATAPI_SECTOR_SIZE = 2048;

    private array $channels = [];
    private array $deviceTypes = [];
    private bool $initialized = false;
    private static ?\WeakMap $instances = null;

    public function __construct(private RuntimeInterface $runtime)
    {
    }

    public static function forRuntime(RuntimeInterface $runtime): self
    {
        if (self::$instances === null) {
            self::$instances = new \WeakMap();
        }

        $instance = self::$instances[$runtime] ?? null;
        if (!$instance instanceof self) {
            $instance = new self($runtime);
            self::$instances[$runtime] = $instance;
        }

        return $instance;
    }

    public function writeRegister(int $port, int $value): void
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        $normPort = $this->normalizePort($port);
        $v = $value & 0xFF;

        switch ($normPort) {
            case 0x1F2:
                $state['sectorCount'] = $v;
                break;
            case 0x1F3:
                $state['lba0'] = $v;
                break;
            case 0x1F4:
                $state['lba1'] = $v;
                break;
            case 0x1F5:
                $state['lba2'] = $v;
                break;
            case 0x1F6:
                $state['driveHead'] = $v;
                $deviceType = $this->deviceTypeFor($channel, $state);
                if ($deviceType === self::DEVICE_ATAPI) {
                    if (($state['lba1'] | $state['lba2']) === 0) {
                        $this->applyDeviceSignature($state, $channel);
                    }
                } else {
                    $this->applyDeviceSignature($state, $channel);
                }
                break;
            case 0x1F7:
                $this->handleCommand($channel, $state, $v);
                break;
            case 0x3F6:
                $state['irqDisabled'] = ($v & 0x02) !== 0;
                $state['srst'] = ($v & 0x04) !== 0;
                if ($state['srst']) {
                    $this->resetChannel($state, $channel);
                }
                break;
            default:
                break;
        }
    }

    public function readRegister(int $port): int
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        if ($this->deviceTypeFor($channel, $state) === self::DEVICE_NONE) {
            return 0xFF;
        }
        $normPort = $this->normalizePort($port);
        $value = match ($normPort) {
            0x1F1 => $state['error'],
            0x1F2 => $state['sectorCount'],
            0x1F3 => $state['lba0'],
            0x1F4 => $state['lba1'],
            0x1F5 => $state['lba2'],
            0x1F6 => $state['driveHead'],
            default => 0,
        };
        if ($this->shouldLogPort($port)) {
            $this->runtime->option()->logger()->debug(sprintf(
                'ATA IN reg port=0x%04X value=0x%02X',
                $port,
                $value & 0xFF
            ));
        }
        return $value;
    }

    public function readStatus(int $port): int
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        if ($this->deviceTypeFor($channel, $state) === self::DEVICE_NONE) {
            return 0xFF;
        }

        $drq = $state['awaitPacket'] || $state['bufferPos'] < count($state['buffer']);
        $errBit = ($state['error'] !== 0) ? 0x01 : 0x00;
        $status = $state['status'];
        if (($status & 0x80) !== 0) {
            return $status;
        }
        $status = ($status & 0xF0) | ($drq ? 0x08 : 0x00) | $errBit;
        $state['status'] = $status;
        if ($this->shouldLogPort($port)) {
            $this->runtime->option()->logger()->debug(sprintf(
                'ATA IN status port=0x%04X value=0x%02X',
                $port,
                $status & 0xFF
            ));
        }
        return $status;
    }

    public function readDataWord(int $port): int
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        $deviceType = $this->deviceTypeFor($channel, $state);
        if ($deviceType === self::DEVICE_NONE) {
            return 0xFFFF;
        }

        if ($state['bufferPos'] >= count($state['buffer'])) {
            return 0;
        }
        $lo = $state['buffer'][$state['bufferPos']] ?? 0;
        $hi = $state['buffer'][$state['bufferPos'] + 1] ?? 0;
        $state['bufferPos'] += 2;
        if ($state['bufferPos'] >= count($state['buffer'])) {
            if ($state['atapiPending'] !== '') {
                $this->loadAtapiPending($channel, $state);
            } else {
                $state['status'] = 0x50;
                $state['sectorCount'] = 0x03;
                if ($deviceType === self::DEVICE_ATAPI) {
                    $this->raiseIrq($channel, $state);
                }
            }
        }
        return ($hi << 8) | $lo;
    }

    public function readDataByte(int $port): int
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        $deviceType = $this->deviceTypeFor($channel, $state);
        if ($deviceType === self::DEVICE_NONE) {
            return 0xFF;
        }

        if ($state['bufferPos'] >= count($state['buffer'])) {
            return 0;
        }
        $byte = $state['buffer'][$state['bufferPos']] ?? 0;
        $state['bufferPos'] += 1;
        if ($state['bufferPos'] >= count($state['buffer'])) {
            if ($state['atapiPending'] !== '') {
                $this->loadAtapiPending($channel, $state);
            } else {
                $state['status'] = 0x50;
                $state['sectorCount'] = 0x03;
                if ($deviceType === self::DEVICE_ATAPI) {
                    $this->raiseIrq($channel, $state);
                }
            }
        }
        return $byte & 0xFF;
    }

    public function writeDataWord(int $port, int $value): void
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        $deviceType = $this->deviceTypeFor($channel, $state);

        if ($deviceType === self::DEVICE_ATAPI && $state['awaitPacket']) {
            $this->appendAtapiPacketWord($channel, $state, $value);
            return;
        }

        if ($deviceType !== self::DEVICE_ATA || !$state['writeMode']) {
            return;
        }

        $lo = $value & 0xFF;
        $hi = ($value >> 8) & 0xFF;
        $state['writeBuffer'][$state['writeBufferPos']++] = $lo;
        $state['writeBuffer'][$state['writeBufferPos']++] = $hi;

        $expectedSize = max(1, $state['sectorCount']) * MediaContext::SECTOR_SIZE;
        if ($state['writeBufferPos'] >= $expectedSize) {
            $this->flushWriteBuffer($state);
            $state['writeMode'] = false;
            $state['status'] = 0x50;
            $this->raiseIrq($channel, $state);
        }
    }

    public function writeDataByte(int $port, int $value): void
    {
        $this->ensureInitialized();
        $channel = $this->channelForPort($port);
        $state = &$this->channels[$channel];
        $deviceType = $this->deviceTypeFor($channel, $state);

        if ($deviceType === self::DEVICE_ATAPI && $state['awaitPacket']) {
            $this->appendAtapiPacketByte($channel, $state, $value & 0xFF);
            return;
        }

        if ($deviceType !== self::DEVICE_ATA || !$state['writeMode']) {
            return;
        }

        $state['writeBuffer'][$state['writeBufferPos']++] = $value & 0xFF;

        $expectedSize = max(1, $state['sectorCount']) * MediaContext::SECTOR_SIZE;
        if ($state['writeBufferPos'] >= $expectedSize) {
            $this->flushWriteBuffer($state);
            $state['writeMode'] = false;
            $state['status'] = 0x50;
            $this->raiseIrq($channel, $state);
        }
    }

    public function readBusMaster(int $port): int
    {
        $this->ensureInitialized();
        $state = &$this->channels['primary'];
        $offset = $port & 0x7;
        return match ($offset) {
            0x0 => $state['bmCommand'],
            0x2 => $state['bmStatus'],
            0x4 => $state['bmPrdLow'],
            0x5 => ($state['bmPrdLow'] >> 8) & 0xFF,
            0x6 => $state['bmPrdHigh'],
            0x7 => ($state['bmPrdHigh'] >> 8) & 0xFF,
            default => 0,
        };
    }

    public function writeBusMaster(int $port, int $value): void
    {
        $this->ensureInitialized();
        $state = &$this->channels['primary'];
        $offset = $port & 0x7;
        $v = $value & 0xFF;
        switch ($offset) {
            case 0x0:
                $state['bmCommand'] = $v;
                if (($v & 0x1) !== 0 && !$state['bmActive']) {
                    $this->beginDma('primary', $state);
                } else {
                    $state['bmActive'] = false;
                    $state['bmStatus'] &= ~0x01;
                }
                break;
            case 0x2:
                if ($v & 0x02) {
                    $state['bmStatus'] &= ~0x02;
                }
                if ($v & 0x04) {
                    $state['bmStatus'] &= ~0x04;
                }
                break;
            case 0x4:
                $state['bmPrdLow'] = ($state['bmPrdLow'] & 0xFFFFFF00) | $v;
                break;
            case 0x5:
                $state['bmPrdLow'] = ($state['bmPrdLow'] & 0xFFFF00FF) | ($v << 8);
                break;
            case 0x6:
                $state['bmPrdHigh'] = ($state['bmPrdHigh'] & 0xFFFFFF00) | $v;
                break;
            case 0x7:
                $state['bmPrdHigh'] = ($state['bmPrdHigh'] & 0xFFFF00FF) | ($v << 8);
                break;
            default:
                break;
        }
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->channels = [
            'primary' => $this->newChannelState(),
            'secondary' => $this->newChannelState(),
        ];

        $hasCd = $this->hasCdromMedia();
        $this->deviceTypes = [
            'primary' => [0 => $hasCd ? self::DEVICE_NONE : self::DEVICE_ATA, 1 => self::DEVICE_NONE],
            'secondary' => [0 => $hasCd ? self::DEVICE_ATAPI : self::DEVICE_NONE, 1 => self::DEVICE_NONE],
        ];

        $this->applyDeviceSignature($this->channels['primary'], 'primary');
        $this->applyDeviceSignature($this->channels['secondary'], 'secondary');

        $this->initialized = true;
    }

    private function newChannelState(): array
    {
        return [
            'sectorCount' => 0,
            'lba0' => 0,
            'lba1' => 0,
            'lba2' => 0,
            'driveHead' => 0,
            'buffer' => [],
            'bufferPos' => 0,
            'status' => 0x50,
            'error' => 0x00,
            'bmCommand' => 0x00,
            'bmStatus' => 0x00,
            'bmPrdLow' => 0x00,
            'bmPrdHigh' => 0x00,
            'bmActive' => false,
            'irqDisabled' => false,
            'srst' => false,
            'writeMode' => false,
            'writeBufferPos' => 0,
            'writeBuffer' => [],
            'awaitPacket' => false,
            'packetExpected' => 0,
            'packetReceived' => 0,
            'packetBuffer' => [],
            'atapiPending' => '',
            'atapiByteCount' => 0,
        ];
    }

    private function channelForPort(int $port): string
    {
        return AtaPort::isSecondaryPort($port) ? 'secondary' : 'primary';
    }

    private function normalizePort(int $port): int
    {
        return AtaPort::isSecondaryPort($port) ? $port + 0x80 : $port;
    }

    private function selectedDrive(array $state): int
    {
        return ($state['driveHead'] >> 4) & 0x01;
    }

    private function deviceTypeFor(string $channel, array $state): string
    {
        $drive = $this->selectedDrive($state);
        return $this->deviceTypes[$channel][$drive] ?? self::DEVICE_NONE;
    }

    private function hasCdromMedia(): bool
    {
        $mediaContext = $this->runtime->logicBoard()->media();
        if (
            $mediaContext->hasDriveType(DriveType::CD_ROM)
            || $mediaContext->hasDriveType(DriveType::CD_RAM)
        ) {
            return true;
        }

        foreach ($mediaContext->all() as $media) {
            if ($media->bootType() === BootType::EL_TORITO) {
                return true;
            }
        }

        return false;
    }

    private function applyDeviceSignature(array &$state, string $channel): void
    {
        $drive = $this->selectedDrive($state);
        $type = $this->deviceTypes[$channel][$drive] ?? self::DEVICE_NONE;

        if ($type === self::DEVICE_ATAPI) {
            $state['lba1'] = 0x14;
            $state['lba2'] = 0xEB;
            $state['status'] = 0x50;
            $state['error'] = 0x00;
            return;
        }

        if ($type === self::DEVICE_ATA) {
            $state['lba1'] = 0x00;
            $state['lba2'] = 0x00;
            $state['status'] = 0x50;
            $state['error'] = 0x00;
            return;
        }

        $state['lba1'] = 0xFF;
        $state['lba2'] = 0xFF;
        $state['status'] = 0x00;
        $state['error'] = 0x00;
    }

    private function resetChannel(array &$state, string $channel): void
    {
        $state = $this->newChannelState();
        $this->applyDeviceSignature($state, $channel);
    }

    private function handleCommand(string $channel, array &$state, int $command): void
    {
        $deviceType = $this->deviceTypeFor($channel, $state);

        $state['error'] = 0;
        $state['buffer'] = [];
        $state['bufferPos'] = 0;
        $state['awaitPacket'] = false;
        $state['atapiPending'] = '';
        $state['atapiByteCount'] = 0;

        switch ($command) {
            case 0x08:
                if ($deviceType === self::DEVICE_ATAPI) {
                    $this->resetChannel($state, $channel);
                    $state['sectorCount'] = 0x03;
                    $state['status'] = 0x50;
                    $this->raiseIrq($channel, $state);
                } elseif ($deviceType === self::DEVICE_ATA) {
                    $this->resetChannel($state, $channel);
                    $state['status'] = 0x50;
                } else {
                    $this->abortCommand($state);
                }
                break;
            case 0x20:
            case 0x21:
                if ($deviceType !== self::DEVICE_ATA) {
                    if ($deviceType === self::DEVICE_ATAPI) {
                        $this->abortAtapiCommand($state);
                    } else {
                        $this->abortCommand($state);
                    }
                    break;
                }
                $state['status'] = 0x80;
                $this->loadBuffer($state);
                if (empty($state['buffer'])) {
                    $state['status'] = 0x41;
                    $state['error'] = 0x04;
                } else {
                    $state['status'] = 0x58;
                    $this->raiseIrq($channel, $state);
                }
                break;
            case 0x30:
            case 0x31:
                if ($deviceType !== self::DEVICE_ATA) {
                    if ($deviceType === self::DEVICE_ATAPI) {
                        $this->abortAtapiCommand($state);
                    } else {
                        $this->abortCommand($state);
                    }
                    break;
                }
                $state['status'] = 0x80;
                $this->prepareWriteBuffer($state);
                $state['status'] = 0x58;
                break;
            case 0xEC:
                if ($deviceType === self::DEVICE_ATAPI) {
                    // Match QEMU: ATAPI devices abort ATA IDENTIFY (0xEC).
                    $this->abortAtapiCommand($state);
                    break;
                }
                if ($deviceType !== self::DEVICE_ATA) {
                    $this->abortCommand($state);
                    break;
                }
                $state['status'] = 0x80;
                $this->loadIdentify($state);
                $state['status'] = 0x58;
                $this->raiseIrq($channel, $state);
                break;
            case 0xA1:
                if ($deviceType !== self::DEVICE_ATAPI) {
                    $this->abortCommand($state);
                    break;
                }
                $state['status'] = 0x80;
                $this->loadIdentifyPacket($state);
                $state['status'] = 0x58;
                $this->raiseIrq($channel, $state);
                break;
            case 0xA0:
                if ($deviceType !== self::DEVICE_ATAPI) {
                    $this->abortCommand($state);
                    break;
                }
                $this->prepareAtapiPacket($state);
                $state['status'] = 0x58;
                $this->raiseIrq($channel, $state);
                break;
            case 0xE7:
                $state['status'] = 0x50;
                break;
            case 0xCA:
                if ($deviceType !== self::DEVICE_ATA) {
                    if ($deviceType === self::DEVICE_ATAPI) {
                        $this->abortAtapiCommand($state);
                    } else {
                        $this->abortCommand($state);
                    }
                    break;
                }
                $state['status'] = 0x80;
                $this->prepareWriteBuffer($state);
                $state['status'] = 0x58;
                break;
            default:
                $state['status'] = 0x50;
                break;
        }
    }

    private function prepareWriteBuffer(array &$state): void
    {
        $state['writeMode'] = true;
        $state['writeBufferPos'] = 0;
        $state['writeBuffer'] = [];
        $state['error'] = 0;
    }

    private function flushWriteBuffer(array &$state): void
    {
        $lba = $state['lba0'] | ($state['lba1'] << 8) | ($state['lba2'] << 16) | (($state['driveHead'] & 0x0F) << 24);
        $offset = $lba * MediaContext::SECTOR_SIZE;

        $proxy = $this->runtime->memory()->proxy();
        if ($state['writeBuffer'] === []) {
            return;
        }

        $data = pack('C*', ...$state['writeBuffer']);
        $originalOffset = $proxy->offset();
        $proxy->setOffset($offset);
        $proxy->write($data);
        $proxy->setOffset($originalOffset);
    }

    private function loadBuffer(array &$state): void
    {
        $lba = $state['lba0'] | ($state['lba1'] << 8) | ($state['lba2'] << 16) | (($state['driveHead'] & 0x0F) << 24);
        $sectors = max(1, $state['sectorCount']);
        $bytesToRead = $sectors * MediaContext::SECTOR_SIZE;

        $state['buffer'] = [];
        $state['bufferPos'] = 0;
        $state['error'] = 0;

        $proxy = $this->runtime->memory()->proxy();
        try {
            $proxy->setOffset($lba * MediaContext::SECTOR_SIZE);
            $data = $proxy->read($bytesToRead);
        } catch (StreamReaderException) {
            $state['buffer'] = [];
            $state['bufferPos'] = 0;
            $state['error'] = 0x04;
            return;
        }

        if ($data === '' || strlen($data) !== $bytesToRead) {
            $state['buffer'] = [];
            $state['bufferPos'] = 0;
            $state['error'] = 0x04;
            return;
        }

        $state['buffer'] = array_values(unpack('C*', $data));
        $state['bufferPos'] = 0;
    }

    private function beginDma(string $channel, array &$state): void
    {
        $state['bmActive'] = true;
        $state['bmStatus'] |= 0x01;
        $state['bmStatus'] &= ~0x06;
        $state['status'] = 0x80;
        $this->loadBuffer($state);
        if (empty($state['buffer'])) {
            $state['bmStatus'] &= ~0x01;
            $state['bmStatus'] |= 0x02 | 0x04;
            $state['status'] = 0x41;
            $state['error'] = 0x04;
            $this->runtime->context()->cpu()->picState()->raiseIrq($channel === 'secondary' ? 15 : 14);
            return;
        }

        $prd = $state['bmPrdLow'] | ($state['bmPrdHigh'] << 16);
        $bufPos = 0;
        $bufLen = count($state['buffer']);

        while (true) {
            $base = $this->readPhysical32($prd);
            $count = $this->readPhysical16($prd + 4);
            $flags = $this->readPhysical16($prd + 6);
            $transfer = $count === 0 ? 0x10000 : $count;

            for ($i = 0; $i < $transfer; $i++) {
                $byte = $bufPos < $bufLen ? $state['buffer'][$bufPos] : 0;
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

        $state['bmActive'] = false;
        $state['bmStatus'] &= ~0x01;
        $state['bmStatus'] |= 0x04;
        $state['status'] = 0x50;
        $this->raiseIrq($channel, $state);
    }

    private function loadIdentify(array &$state): void
    {
        $buffer = array_fill(0, 512, 0);
        $buffer[0] = 0x40;
        $buffer[1] = 0x00;
        $this->writeIdentifyString($buffer, 23, 4, '1.0');
        $this->writeIdentifyString($buffer, 27, 20, 'PHP ATA DISK');
        $sectors = 0x01000000;
        $buffer[120] = $sectors & 0xFF;
        $buffer[121] = ($sectors >> 8) & 0xFF;
        $buffer[122] = ($sectors >> 16) & 0xFF;
        $buffer[123] = ($sectors >> 24) & 0xFF;
        $buffer[98] = 0x0F;
        $buffer[99] = 0x00;

        $state['buffer'] = $buffer;
        $state['bufferPos'] = 0;
        $state['error'] = 0;
    }

    private function loadIdentifyPacket(array &$state): void
    {
        $buffer = array_fill(0, 512, 0);
        // Match QEMU-style ATAPI identify data for broad DOS driver compatibility.
        $this->writeIdentifyWord($buffer, 0, 0x85C0);  // Removable ATAPI device, 12-byte packets.
        $this->writeIdentifyWord($buffer, 20, 3);      // Buffer type.
        $this->writeIdentifyWord($buffer, 21, 512);    // Cache size in sectors.
        $this->writeIdentifyWord($buffer, 22, 4);      // ECC bytes.
        $this->writeIdentifyString($buffer, 23, 4, '2.5+');
        $this->writeIdentifyString($buffer, 27, 20, 'PHP ATAPI CD-ROM');
        $this->writeIdentifyWord($buffer, 48, 1);      // Dword I/O.
        $this->writeIdentifyWord($buffer, 49, 1 << 9); // LBA supported.
        $this->writeIdentifyWord($buffer, 53, 3);      // Words 64-70, 54-58 valid.
        $this->writeIdentifyWord($buffer, 63, 0x0103); // DMA modes (conservative).
        $this->writeIdentifyWord($buffer, 64, 3);      // PIO3-4 supported.
        $this->writeIdentifyWord($buffer, 65, 0x00B4); // Min DMA cycle time.
        $this->writeIdentifyWord($buffer, 66, 0x00B4); // Rec DMA cycle time.
        $this->writeIdentifyWord($buffer, 67, 0x012C); // Min PIO cycle time.
        $this->writeIdentifyWord($buffer, 68, 0x00B4); // Min PIO w/ IORDY.
        $this->writeIdentifyWord($buffer, 71, 30);
        $this->writeIdentifyWord($buffer, 72, 30);
        $this->writeIdentifyWord($buffer, 80, 0x001E); // ATA/ATAPI-4.

        $state['buffer'] = $buffer;
        $state['bufferPos'] = 0;
        $state['error'] = 0;
    }

    private function writeIdentifyString(array &$buffer, int $wordIndex, int $wordCount, string $text): void
    {
        $text = str_pad(substr($text, 0, $wordCount * 2), $wordCount * 2, ' ');
        $offset = $wordIndex * 2;
        for ($i = 0; $i < $wordCount * 2; $i += 2) {
            $buffer[$offset + $i] = ord($text[$i + 1]);
            $buffer[$offset + $i + 1] = ord($text[$i]);
        }
    }

    private function writeIdentifyWord(array &$buffer, int $wordIndex, int $value): void
    {
        $offset = $wordIndex * 2;
        $buffer[$offset] = $value & 0xFF;
        $buffer[$offset + 1] = ($value >> 8) & 0xFF;
    }

    private function prepareAtapiPacket(array &$state): void
    {
        $state['awaitPacket'] = true;
        $state['sectorCount'] = 0x01;
        $state['packetExpected'] = self::ATAPI_PACKET_SIZE;
        $state['packetReceived'] = 0;
        $state['packetBuffer'] = [];
        $state['buffer'] = [];
        $state['bufferPos'] = 0;
        $state['atapiPending'] = '';
        $state['atapiByteCount'] = 0;
    }

    private function appendAtapiPacketWord(string $channel, array &$state, int $value): void
    {
        $state['packetBuffer'][] = $value & 0xFF;
        $state['packetBuffer'][] = ($value >> 8) & 0xFF;
        $state['packetReceived'] += 2;

        if ($state['packetReceived'] >= $state['packetExpected']) {
            $state['awaitPacket'] = false;
            $packet = array_slice($state['packetBuffer'], 0, $state['packetExpected']);
            $this->handleAtapiPacket($channel, $state, $packet);
        }
    }

    private function appendAtapiPacketByte(string $channel, array &$state, int $value): void
    {
        $state['packetBuffer'][] = $value & 0xFF;
        $state['packetReceived'] += 1;

        if ($state['packetReceived'] >= $state['packetExpected']) {
            $state['awaitPacket'] = false;
            $packet = array_slice($state['packetBuffer'], 0, $state['packetExpected']);
            $this->handleAtapiPacket($channel, $state, $packet);
        }
    }

    private function handleAtapiPacket(string $channel, array &$state, array $packet): void
    {
        $opcode = $packet[0] ?? 0x00;
        $state['error'] = 0x00;
        $this->runtime->option()->logger()->debug(sprintf(
            'ATAPI packet opcode=0x%02X channel=%s',
            $opcode,
            $channel
        ));

        switch ($opcode) {
            case 0x00:
                $state['status'] = 0x50;
                $state['sectorCount'] = 0x03;
                $this->raiseIrq($channel, $state);
                break;
            case 0x03:
                $alloc = $packet[4] ?? 0;
                $data = $this->buildSenseData();
                if ($alloc > 0) {
                    $data = substr($data, 0, $alloc);
                }
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            case 0x12:
                $alloc = $packet[4] ?? 0;
                $data = $this->buildInquiryData();
                if ($alloc > 0) {
                    $data = substr($data, 0, $alloc);
                }
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            case 0x1A:
                $alloc = $packet[4] ?? 0;
                $data = "\x03\x00\x00\x00";
                if ($alloc > 0) {
                    $data = substr($data, 0, $alloc);
                }
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            case 0x1B:
                $state['status'] = 0x50;
                $state['sectorCount'] = 0x03;
                $this->raiseIrq($channel, $state);
                break;
            case 0x25:
                $data = $this->buildReadCapacityData();
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            case 0x28:
                $lba = (($packet[2] ?? 0) << 24)
                    | (($packet[3] ?? 0) << 16)
                    | (($packet[4] ?? 0) << 8)
                    | ($packet[5] ?? 0);
                $sectors = (($packet[7] ?? 0) << 8) | ($packet[8] ?? 0);
                $this->runtime->option()->logger()->debug(sprintf(
                    'ATAPI READ(10) lba=%d sectors=%d',
                    $lba,
                    $sectors
                ));
                if ($sectors === 0) {
                    $state['status'] = 0x50;
                    $state['sectorCount'] = 0x03;
                    $this->raiseIrq($channel, $state);
                    break;
                }
                $data = $this->readCdSectors($lba, $sectors);
                if ($data === '') {
                    $this->abortCommand($state);
                    break;
                }
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            case 0x43:
                $alloc = (($packet[7] ?? 0) << 8) | ($packet[8] ?? 0);
                $msf = (($packet[1] ?? 0) & 0x02) !== 0;
                $data = $this->buildReadTocData($msf);
                if ($alloc > 0) {
                    $data = substr($data, 0, $alloc);
                }
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            case 0x5A:
                $alloc = (($packet[7] ?? 0) << 8) | ($packet[8] ?? 0);
                $data = "\x00\x06\x00\x00\x00\x00\x00\x00";
                if ($alloc > 0) {
                    $data = substr($data, 0, $alloc);
                }
                $this->queueAtapiData($state, $data);
                $this->raiseIrq($channel, $state);
                break;
            default:
                $this->abortCommand($state);
                break;
        }
    }

    private function queueAtapiData(array &$state, string $data): void
    {
        $byteCount = $this->atapiByteCount($state);
        $state['atapiByteCount'] = $byteCount;

        if ($byteCount > 0 && strlen($data) > $byteCount) {
            $state['atapiPending'] = substr($data, $byteCount);
            $data = substr($data, 0, $byteCount);
        } else {
            $state['atapiPending'] = '';
        }

        $chunkLen = strlen($data);
        $state['lba1'] = $chunkLen & 0xFF;
        $state['lba2'] = ($chunkLen >> 8) & 0xFF;

        $this->runtime->option()->logger()->debug(sprintf(
            'ATAPI queue data bytes=%d byteCount=%d pending=%d',
            $chunkLen,
            $byteCount,
            strlen($state['atapiPending'])
        ));

        $this->setBufferFromString($state, $data);
        $state['sectorCount'] = 0x02;
        $state['status'] = 0x58;
    }

    private function loadAtapiPending(string $channel, array &$state): void
    {
        $byteCount = $state['atapiByteCount'] > 0 ? $state['atapiByteCount'] : $this->atapiByteCount($state);
        if ($byteCount <= 0) {
            $byteCount = 0x10000;
        }
        $chunk = substr($state['atapiPending'], 0, $byteCount);
        $state['atapiPending'] = substr($state['atapiPending'], strlen($chunk));
        $chunkLen = strlen($chunk);
        $state['lba1'] = $chunkLen & 0xFF;
        $state['lba2'] = ($chunkLen >> 8) & 0xFF;
        $this->setBufferFromString($state, $chunk);
        $state['status'] = 0x58;
        $this->raiseIrq($channel, $state);
    }

    private function atapiByteCount(array $state): int
    {
        $count = (($state['lba2'] & 0xFF) << 8) | ($state['lba1'] & 0xFF);
        return $count === 0 ? 0x10000 : $count;
    }

    private function setBufferFromString(array &$state, string $data): void
    {
        if ($data === '') {
            $state['buffer'] = [];
            $state['bufferPos'] = 0;
            return;
        }
        $state['buffer'] = array_values(unpack('C*', $data));
        $state['bufferPos'] = 0;
    }

    private function abortCommand(array &$state): void
    {
        $state['status'] = 0x41;
        $state['error'] = 0x04;
        $state['awaitPacket'] = false;
        $state['buffer'] = [];
        $state['bufferPos'] = 0;
        $state['atapiPending'] = '';
        $state['atapiByteCount'] = 0;
    }

    private function abortAtapiCommand(array &$state): void
    {
        $this->abortCommand($state);
        $state['lba1'] = 0x14;
        $state['lba2'] = 0xEB;
        $state['sectorCount'] = 0x03;
    }

    private function buildInquiryData(): string
    {
        $vendor = str_pad('PHP', 8, ' ');
        $product = str_pad('ATAPI CD-ROM', 16, ' ');
        $revision = str_pad('2.5+', 4, ' ');
        return chr(0x05) . chr(0x80) . chr(0x00) . chr(0x21) . chr(31) . "\x00\x00\x00"
            . $vendor . $product . $revision;
    }

    private function buildSenseData(): string
    {
        return "\x70\x00\x00\x00\x00\x00\x00\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    }

    private function buildReadCapacityData(): string
    {
        $size = $this->cdSize();
        $lastLba = $size > 0 ? max(0, intdiv($size, self::ATAPI_SECTOR_SIZE) - 1) : 0;
        return pack('N', $lastLba) . pack('N', self::ATAPI_SECTOR_SIZE);
    }

    private function buildReadTocData(bool $msf): string
    {
        $lastLba = max(0, intdiv($this->cdSize(), self::ATAPI_SECTOR_SIZE) - 1);
        $leadOut = $lastLba + 1;

        $entries = [];
        $entries[] = $this->buildTocEntry(0x14, 0x01, 0, $msf);
        $entries[] = $this->buildTocEntry(0x16, 0xAA, $leadOut, $msf);

        $payload = implode('', $entries);
        $length = strlen($payload) + 2;
        return pack('n', $length) . chr(1) . chr(1) . $payload;
    }

    private function buildTocEntry(int $controlAdr, int $trackNumber, int $lba, bool $msf): string
    {
        if ($msf) {
            [$m, $s, $f] = $this->lbaToMsf($lba);
            $addr = chr(0x00) . chr($m) . chr($s) . chr($f);
        } else {
            $addr = pack('N', $lba);
        }
        return chr(0x00) . chr($controlAdr) . chr($trackNumber) . chr(0x00) . $addr;
    }

    private function lbaToMsf(int $lba): array
    {
        $lba += 150;
        $minute = intdiv($lba, 4500);
        $lba %= 4500;
        $second = intdiv($lba, 75);
        $frame = $lba % 75;
        return [$minute, $second, $frame];
    }

    private function readCdSectors(int $lba, int $sectors): string
    {
        if ($sectors <= 0) {
            return '';
        }
        $media = $this->runtime->logicBoard()->media()->primary();
        if ($media !== null) {
            $data = $media->stream()->readIsoSectors($lba, $sectors);
            if ($data !== null) {
                return $data;
            }
        }
        $proxy = $this->runtime->memory()->proxy();
        if ($proxy instanceof ISOStreamProxy) {
            return $proxy->readCDSectors($lba, $sectors);
        }
        return '';
    }

    private function cdSize(): int
    {
        if (!$this->hasCdromMedia()) {
            return 0;
        }

        $mediaContext = $this->runtime->logicBoard()->media();
        $media = $mediaContext->primary();
        if ($media !== null) {
            $size = $media->stream()->backingFileSize();
            if ($size > 0) {
                return $size;
            }
        }
        $proxy = $this->runtime->memory()->proxy();
        if ($proxy instanceof ISOStreamProxy) {
            return $proxy->isoFileSize();
        }
        return 0;
    }

    private function raiseIrq(string $channel, array $state): void
    {
        if ($state['irqDisabled']) {
            return;
        }
        $irq = $channel === 'secondary' ? 15 : 14;
        $this->runtime->context()->cpu()->picState()->raiseIrq($irq);
    }

    private function shouldLogPort(int $port): bool
    {
        return ($port >= 0x170 && $port <= 0x177)
            || ($port >= 0x1F0 && $port <= 0x1F7)
            || $port === 0x376
            || $port === 0x3F6;
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
        $this->runtime->memoryAccessor()->writeRawByte($addr, $value & 0xFF);
    }
}
