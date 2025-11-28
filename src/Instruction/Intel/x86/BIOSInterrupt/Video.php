<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Exception\VideoInterruptException;
use PHPMachineEmulator\Instruction\Intel\ServiceFunction\VideoServiceFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorFetchResultInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;
use PHPMachineEmulator\Exception\FaultException;

class Video implements InterruptInterface
{
    private int $currentMode = 0x03;
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private static bool $vbeInitialized = false;
    private static int $vbeModeInfoAddr = 0x2200;
    private static int $vbeModeListAddr = 0x2000;

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function process(RuntimeInterface $runtime): void
    {
        $fetchResult = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $fetchResult->asHighBit();
        $al = $fetchResult->asLowBit();
        $runtime->option()->logger()->debug(sprintf('Video INT: AH=0x%02X AL=0x%02X (char=%s)', $ah, $al, chr($al)));

        match ($serviceFunction = VideoServiceFunction::from($ah)) {
            VideoServiceFunction::SET_VIDEO_MODE => $this->setVideoMode($runtime, $fetchResult),
            VideoServiceFunction::TELETYPE_OUTPUT => $this->teletypeOutput($runtime, $fetchResult),

            VideoServiceFunction::SET_CURSOR_SHAPE,
            VideoServiceFunction::SET_CURSOR_POSITION => $this->setCursorPosition($runtime),
            VideoServiceFunction::GET_CURSOR_POSITION => $this->getCursorPosition($runtime),
            VideoServiceFunction::SELECT_ACTIVE_DISPLAY_PAGE,
            VideoServiceFunction::SET_ACTIVE_DISPLAY_PAGE,
            VideoServiceFunction::SCROLL_UP_WINDOW,
            VideoServiceFunction::SCROLL_DOWN_WINDOW,
            VideoServiceFunction::READ_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION,
            VideoServiceFunction::WRITE_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION,
            VideoServiceFunction::WRITE_CHARACTER_ONLY_AT_CURSOR_POSITION,
            VideoServiceFunction::SET_COLOR_PALETTE,
            VideoServiceFunction::READ_PIXEL,
            VideoServiceFunction::WRITE_PIXEL,
            VideoServiceFunction::GET_CURRENT_VIDEO_MODE => $this->getCurrentVideoMode($runtime),
            // VBE extensions (0x4Fxx)
            default => $this->handleVbe($runtime, $fetchResult),
        };
    }

    protected function setVideoMode(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $runtime->option()->logger()->debug('Set Video Mode');

        $videoType = $fetchResult->asLowBit();

        // NOTE: validate video type
        $video = $runtime->video()->supportedVideoModes()[$videoType] ?? null;
        if ($video === null) {
            // unsupported: gracefully ignore to let boot continue
            return;
        }
        $this->currentMode = $videoType;

        $runtime->option()->logger()->debug(
            sprintf(
                'Render video size %dx%d',
                $video->width,
                $video->height,
            )
        );

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeBySize(
                $runtime->video()->videoTypeFlagAddress(),
                // NOTE: Store width, height, and video type in a single flag address.
                // width: 16 bits (bits 48..63)
                // height: 16 bits (bits 32..47)
                // video type: 8 bits (bits 0..7)
                (($video->width & 0xFFFF) << 48) +
                (($video->height & 0xFFFF) << 32) +
                ($videoType & 0xFF),
                64,
            );

        // Update screen writer with new video mode
        $runtime->context()->screen()->updateVideoMode($video);
    }

    protected function teletypeOutput(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $runtime->context()->screen()->write($fetchResult->asLowBitChar());
    }

    protected function setCursorPosition(RuntimeInterface $runtime): void
    {
        $row = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asHighBit();
        $col = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $this->cursorRow = $row;
        $this->cursorCol = $col;
    }

    protected function getCursorPosition(RuntimeInterface $runtime): void
    {
        $edx = ($this->cursorRow << 8) | ($this->cursorCol & 0xFF);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EDX, $edx);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write8Bit(RegisterType::BH, 0); // page 0
    }

    protected function getCurrentVideoMode(RuntimeInterface $runtime): void
    {
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write8Bit(RegisterType::AL, $this->currentMode & 0xFF);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write8Bit(RegisterType::AH, 0x20); // text mode, 8x16 font
    }

    protected function handleVbe(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $ax = $fetchResult->asByte();
        $ah = ($ax >> 8) & 0xFF;
        $al = $ax & 0xFF;
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

        if (!self::$vbeInitialized) {
            $this->initVbeStructures($runtime);
            self::$vbeInitialized = true;
        }

        if ($al !== 0x4F) {
            $ma->write16Bit(RegisterType::AX, 0x014F);
            return;
        }

        $func = $ah;
        if ($func === 0x00) {
            $this->vbeGetInfo($runtime);
            return;
        }
        if ($func === 0x01) {
            $this->vbeGetModeInfo($runtime);
            return;
        }
        if ($func === 0x02) {
            $this->vbeSetMode($runtime);
            return;
        }
        if ($func === 0x03) {
            $this->vbeGetCurrentMode($runtime);
            return;
        }

        $ma->write16Bit(RegisterType::AX, 0x014F);
    }

    private function initVbeStructures(RuntimeInterface $runtime): void
    {
        // Mode list at 0x2000: terminate with 0xFFFF
        $list = [0x118, 0x141, 0xFFFF];
        $addr = self::$vbeModeListAddr;
        foreach ($list as $mode) {
            $this->writeMemory16($runtime, $addr, $mode & 0xFFFF);
            $addr += 2;
        }

        // Mode info block at 0x2200 for mode 0x141 (1024x768x32)
        $base = self::$vbeModeInfoAddr;
        $this->writeMemory16($runtime, $base + 0x00, 0x009B); // attributes: mode supported, color, graphics, LFB
        $this->writeMemory8($runtime, $base + 0x02, 0); // window A
        $this->writeMemory8($runtime, $base + 0x03, 0);
        $this->writeMemory16($runtime, $base + 0x04, 0);
        $this->writeMemory16($runtime, $base + 0x06, 0);
        $this->writeMemory16($runtime, $base + 0x08, 0);
        $this->writeMemory16($runtime, $base + 0x0A, 0);
        $this->writeMemory8($runtime, $base + 0x0C, 0);
        $this->writeMemory8($runtime, $base + 0x0D, 0);
        $this->writeMemory16($runtime, $base + 0x12, 1024); // X
        $this->writeMemory16($runtime, $base + 0x14, 768);  // Y
        $this->writeMemory8($runtime, $base + 0x18, 32);   // bpp
        $this->writeMemory8($runtime, $base + 0x19, 1);    // memory model packed pixel
        $this->writeMemory16($runtime, $base + 0x10, 1);   // number of planes
        $this->writeMemory16($runtime, $base + 0x1A, 32);  // bytes per scan line / pixel byte?
        $this->writeMemory16($runtime, $base + 0x1A, 4096); // line length
        $this->writeMemory16($runtime, $base + 0x1C, 32);  // image base?
        $this->writeMemory16($runtime, $base + 0x1E, 1);   // pages
        $this->writeMemory8($runtime, $base + 0x1F, 8);    // red mask
        $this->writeMemory8($runtime, $base + 0x20, 16);   // red position
        $this->writeMemory8($runtime, $base + 0x21, 8);    // green mask
        $this->writeMemory8($runtime, $base + 0x22, 8);    // green pos
        $this->writeMemory8($runtime, $base + 0x23, 8);    // blue mask
        $this->writeMemory8($runtime, $base + 0x24, 0);    // blue pos
        $this->writeMemory8($runtime, $base + 0x25, 8);    // rsvd mask
        $this->writeMemory8($runtime, $base + 0x26, 0);
        $this->writeMemory8($runtime, $base + 0x27, 0);    // direct color
        $this->writeMemory32($runtime, $base + 0x28, 0xE0000000); // phys base
        $this->writeMemory16($runtime, $base + 0x2E, 1);   // lin bytes per scan line in 32bpp
        $this->writeMemory16($runtime, $base + 0x2E, 4096);
    }

    private function vbeGetInfo(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $addr = $this->segmentOffsetAddress($runtime, RegisterType::ES, $ma->fetch(RegisterType::EDI)->asBytesBySize($runtime->context()->cpu()->addressSize()));
        // Zero 512 bytes
        for ($i = 0; $i < 512; $i++) {
            $this->writeMemory8($runtime, $addr + $i, 0);
        }
        $this->writeMemory8($runtime, $addr, ord('V'));
        $this->writeMemory8($runtime, $addr + 1, ord('E'));
        $this->writeMemory8($runtime, $addr + 2, ord('S'));
        $this->writeMemory8($runtime, $addr + 3, ord('A'));
        $this->writeMemory16($runtime, $addr + 4, 0x0300); // version
        // OEM, vendor/product strings omitted
        // Video mode pointer
        $this->writeMemory16($runtime, $addr + 0x0E, self::$vbeModeListAddr & 0xF); // offset
        $this->writeMemory16($runtime, $addr + 0x10, (self::$vbeModeListAddr >> 4) & 0xFFFF); // segment
        $this->writeMemory16($runtime, $addr + 0x12, 0x0080); // total memory (64KB blocks) -> 8MB
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }

    private function vbeGetModeInfo(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $mode = $ma->fetch(RegisterType::ECX)->asBytesBySize(16) & 0xFFFF;
        $dest = $this->segmentOffsetAddress($runtime, RegisterType::ES, $ma->fetch(RegisterType::EDI)->asBytesBySize($runtime->context()->cpu()->addressSize()));
        // Only one mode: 0x141
        if ($mode !== 0x141) {
            $ma->write16Bit(RegisterType::AX, 0x014F);
            return;
        }
        // copy mode info block
        for ($i = 0; $i < 256; $i++) {
            $byte = $this->readMemory8($runtime, self::$vbeModeInfoAddr + $i);
            $this->writeMemory8($runtime, $dest + $i, $byte);
        }
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }

    private function vbeSetMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $mode = $ma->fetch(RegisterType::EBX)->asBytesBySize(16) & 0x1FF;
        if ($mode === 0x141) {
            $this->currentMode = $mode;
            $ma->write16Bit(RegisterType::AX, 0x004F);
            return;
        }
        $ma->write16Bit(RegisterType::AX, 0x014F);
    }

    private function vbeGetCurrentMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ma->write16Bit(RegisterType::BX, $this->currentMode & 0x1FF);
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }
}
