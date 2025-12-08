<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Instruction\Intel\ServiceFunction\VideoServiceFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Device\VideoContextInterface;
use PHPMachineEmulator\Runtime\MemoryAccessorFetchResultInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Video implements InterruptInterface
{
    private static bool $vbeInitialized = false;
    private static int $vbeModeInfoAddr = 0x2200;
    private static int $vbeModeListAddr = 0x2000;

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    /**
     * Get the video context from DeviceManager.
     *
     * @throws \RuntimeException if video context is not registered
     */
    protected function videoContext(): VideoContextInterface
    {
        return $this->runtime->context()->devices()->video();
    }

    public function process(RuntimeInterface $runtime): void
    {
        $fetchResult = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $fetchResult->asHighBit();
        $al = $fetchResult->asLowBit();
        // Only log visible ASCII characters for teletype output
        if ($ah === 0x0E && $al >= 0x20 && $al < 0x7F) {
            $runtime->option()->logger()->debug(sprintf('PRINT: %s (0x%02X)', chr($al), $al));
        }

        $serviceFunction = VideoServiceFunction::tryFrom($ah);

        match ($serviceFunction) {
            VideoServiceFunction::SET_VIDEO_MODE => $this->setVideoMode($runtime, $fetchResult),
            VideoServiceFunction::TELETYPE_OUTPUT => $this->teletypeOutput($runtime, $fetchResult),
            VideoServiceFunction::SET_CURSOR_SHAPE => null, // stub
            VideoServiceFunction::SET_CURSOR_POSITION => $this->setCursorPosition($runtime),
            VideoServiceFunction::GET_CURSOR_POSITION => $this->getCursorPosition($runtime),
            VideoServiceFunction::SELECT_ACTIVE_DISPLAY_PAGE => null, // stub
            VideoServiceFunction::SET_ACTIVE_DISPLAY_PAGE => null, // stub
            VideoServiceFunction::SCROLL_UP_WINDOW => $this->scrollUpWindow($runtime),
            VideoServiceFunction::SCROLL_DOWN_WINDOW => null, // stub
            VideoServiceFunction::READ_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION => null, // stub
            VideoServiceFunction::WRITE_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION => $this->writeCharAndAttr($runtime, $fetchResult),
            VideoServiceFunction::WRITE_CHARACTER_ONLY_AT_CURSOR_POSITION => $this->writeCharOnly($runtime, $fetchResult),
            VideoServiceFunction::SET_COLOR_PALETTE => null, // stub
            VideoServiceFunction::READ_PIXEL => null, // stub
            VideoServiceFunction::WRITE_PIXEL => null, // stub
            VideoServiceFunction::GET_CURRENT_VIDEO_MODE => $this->getCurrentVideoMode($runtime),
            VideoServiceFunction::PALETTE_ATTRIBUTE_CONTROL => $this->handlePaletteControl($runtime, $fetchResult),
            // VBE extensions (0x4Fxx) or unsupported functions
            default => $this->handleExtendedOrUnsupported($runtime, $fetchResult, $ah),
        };
    }

    protected function setVideoMode(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $videoType = $fetchResult->asLowBit();
        $runtime->option()->logger()->debug(sprintf('Set Video Mode: 0x%02X', $videoType));

        // NOTE: validate video type
        $video = $runtime->video()->supportedVideoModes()[$videoType] ?? null;
        if ($video === null) {
            // unsupported: gracefully ignore to let boot continue
            return;
        }
        $this->videoContext()->setCurrentMode($videoType);

        $runtime
            ->memoryAccessor()
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
        $char = $fetchResult->asLowBit();
        $videoContext = $this->videoContext();

        // Use ANSI parser from VideoContext
        if ($videoContext->ansiParser()->processChar($char, $runtime, $videoContext)) {
            // Character was consumed by ANSI parser
            return;
        }

        // Handle control characters
        if ($char === 0x0D) {
            // CR - Carriage Return: move to beginning of line
            $videoContext->setCursorPosition($videoContext->getCursorRow(), 0);
            $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
            return;
        }
        if ($char === 0x0A) {
            // LF - Line Feed: move to next line
            $videoContext->setCursorPosition($videoContext->getCursorRow() + 1, $videoContext->getCursorCol());
            $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
            return;
        }
        if ($char === 0x08) {
            // BS - Backspace: move cursor left
            if ($videoContext->getCursorCol() > 0) {
                $videoContext->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol() - 1);
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
            }
            return;
        }
        if ($char === 0x07) {
            // BEL - Bell: just ignore
            return;
        }

        // Regular character: write and advance cursor
        $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
        $runtime->context()->screen()->write(chr($char));
        $newCol = $videoContext->getCursorCol() + 1;

        // Handle line wrap based on current video mode width
        $videoMode = $runtime->video()->supportedVideoModes()[$videoContext->getCurrentMode()] ?? null;
        $cols = $videoMode?->width ?? 80;
        if ($newCol >= $cols) {
            $videoContext->setCursorPosition($videoContext->getCursorRow() + 1, 0);
        } else {
            $videoContext->setCursorPosition($videoContext->getCursorRow(), $newCol);
        }
    }

    protected function setCursorPosition(RuntimeInterface $runtime): void
    {
        // DH = row, DL = column
        $row = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asHighBit();
        $col = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $this->videoContext()->setCursorPosition($row, $col);
        $runtime->option()->logger()->debug(sprintf('SET_CURSOR_POSITION: row=%d, col=%d', $row, $col));
        // Update the screen writer's cursor position
        $runtime->context()->screen()->setCursorPosition($row, $col);
    }

    protected function getCursorPosition(RuntimeInterface $runtime): void
    {
        $videoContext = $this->videoContext();
        $edx = ($videoContext->getCursorRow() << 8) | ($videoContext->getCursorCol() & 0xFF);
        $runtime->memoryAccessor()->write16Bit(RegisterType::EDX, $edx);
        // BH = page number (0), BL = attribute. Set BX to 0 for page 0
        $runtime->memoryAccessor()->write16Bit(RegisterType::EBX, 0);
    }

    protected function getCurrentVideoMode(RuntimeInterface $runtime): void
    {
        // INT 10h AH=0Fh - Get Current Video Mode
        // Returns:
        //   AL = current video mode
        //   AH = number of screen columns
        //   BH = active display page
        $currentMode = $this->videoContext()->getCurrentMode();
        $videoMode = $runtime->video()->supportedVideoModes()[$currentMode] ?? null;
        $cols = $videoMode?->width ?? 80;

        $axValue = ($cols << 8) | ($currentMode & 0xFF);
        $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $axValue);
        // BH = current page (0)
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EBX, 0);
    }

    protected function handlePaletteControl(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=10h - Palette/Attribute Control
        // AL=00h: Set individual palette register
        // AL=01h: Set overscan (border) color
        // AL=02h: Set all palette registers
        // AL=03h: Toggle intensity/blinking bit (BL=0 enable intensity, BL=1 enable blinking)
        // We simply stub these operations for now
        $al = $fetchResult->asLowBit();
        $runtime->option()->logger()->debug(sprintf('Palette control: AL=0x%02X (stub)', $al));
    }

    protected function scrollUpWindow(RuntimeInterface $runtime): void
    {
        // INT 10h AH=06h - Scroll Up Window
        // AL = number of lines to scroll (0 = clear entire window)
        // BH = attribute for blank lines
        // CH,CL = row,column of upper left corner
        // DH,DL = row,column of lower right corner
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(16);
        $attribute = ($bx >> 8) & 0xFF; // BH = attribute for blank lines
        $cx = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);
        $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16);

        $topRow = ($cx >> 8) & 0xFF;    // CH
        $leftCol = $cx & 0xFF;           // CL
        $bottomRow = ($dx >> 8) & 0xFF;  // DH
        $rightCol = $dx & 0xFF;          // DL

        $width = $rightCol - $leftCol + 1;
        $height = $bottomRow - $topRow + 1;

        $runtime->option()->logger()->debug(sprintf(
            'SCROLL_UP: AL=%d, attr=0x%02X, top=%d, left=%d, bottom=%d, right=%d',
            $al, $attribute, $topRow, $leftCol, $bottomRow, $rightCol
        ));

        if ($al === 0) {
            // Clear entire window with the specified attribute
            $runtime->context()->screen()->fillArea($topRow, $leftCol, $width, $height, $attribute);
        }
        // Otherwise, stub for now - actual scrolling would require screen buffer manipulation
    }

    protected function writeCharAndAttr(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=09h - Write Character and Attribute at Cursor Position
        // AL = character to write
        // BH = page number
        // BL = attribute (text mode) or color (graphics mode)
        // CX = number of times to write character
        $char = chr($fetchResult->asLowBit());
        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(16);
        $attribute = $bx & 0xFF; // BL = attribute
        $count = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);

        $runtime->option()->logger()->debug(sprintf('WRITE_CHAR_ATTR: char=0x%02X attr=0x%02X count=%d', ord($char), $attribute, $count));

        // Write character at current cursor position with attribute (doesn't advance cursor)
        $runtime->context()->screen()->writeCharAtCursor($char, $count, $attribute);
    }

    protected function writeCharOnly(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=0Ah - Write Character Only at Cursor Position
        // AL = character to write
        // BH = page number
        // CX = number of times to write character
        $char = chr($fetchResult->asLowBit());
        $count = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);

        // Write character at current cursor position (doesn't advance cursor)
        $runtime->context()->screen()->writeCharAtCursor($char, $count);
    }

    protected function handleVbe(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $ax = $fetchResult->asByte();
        $ah = ($ax >> 8) & 0xFF;
        $al = $ax & 0xFF;
        $ma = $runtime->memoryAccessor();

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
        $ma = $runtime->memoryAccessor();
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
        $ma = $runtime->memoryAccessor();
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
        $ma = $runtime->memoryAccessor();
        $mode = $ma->fetch(RegisterType::EBX)->asBytesBySize(16) & 0x1FF;
        if ($mode === 0x141) {
            $this->videoContext()->setCurrentMode($mode);
            $ma->write16Bit(RegisterType::AX, 0x004F);
            return;
        }
        $ma->write16Bit(RegisterType::AX, 0x014F);
    }

    private function vbeGetCurrentMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->write16Bit(RegisterType::BX, $this->videoContext()->getCurrentMode() & 0x1FF);
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }

    /**
     * Calculate segment:offset to linear address.
     */
    protected function segmentOffsetAddress(RuntimeInterface $runtime, int $segment, int $offset): int
    {
        if ($runtime->context()->cpu()->isProtectedMode()) {
            // In protected mode, segment is a selector - for simplicity use offset directly
            // Real implementation would look up GDT/LDT for base address
            return $offset;
        }
        // Real mode: segment * 16 + offset
        $segBase = $runtime->memoryAccessor()->fetch($segment)->asBytesBySize(16);
        return ($segBase << 4) + $offset;
    }

    /**
     * Read 8-bit value from memory.
     */
    protected function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        try {
            $value = $runtime->memoryAccessor()->readRawByte($address);
            if ($value !== null) {
                return $value;
            }
            $memory = $runtime->memory();
            $currentOffset = $memory->offset();
            $memory->setOffset($address);
            $byte = $memory->byte();
            $memory->setOffset($currentOffset);
            return $byte;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Write 8-bit value to memory.
     */
    protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void
    {
        try {
            $runtime->memoryAccessor()->writeRawByte($address, $value & 0xFF);
        } catch (\Throwable) {
            // Ignore write errors
        }
    }

    /**
     * Write 16-bit value to memory (little-endian).
     */
    protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
    }

    /**
     * Write 32-bit value to memory (little-endian).
     */
    protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
        $this->writeMemory8($runtime, $address + 2, ($value >> 16) & 0xFF);
        $this->writeMemory8($runtime, $address + 3, ($value >> 24) & 0xFF);
    }

    /**
     * Handle extended or unsupported video functions.
     */
    protected function handleExtendedOrUnsupported(
        RuntimeInterface $runtime,
        MemoryAccessorFetchResultInterface $fetchResult,
        int $ah
    ): void {
        // VBE functions (AH=0x4F)
        if ($ah === 0x4F) {
            $this->handleVbe($runtime, $fetchResult);
            return;
        }

        // AH=0x1C: Video State Save/Restore (stub - just return function not supported)
        if ($ah === 0x1C) {
            $al = $fetchResult->asLowBit();
            $ma = $runtime->memoryAccessor();

            // AL=0x00: Get state buffer size
            // AL=0x01: Save state
            // AL=0x02: Restore state
            // Return AL=0x1C (function supported) with minimal implementation
            $ma->writeToLowBit(RegisterType::EAX, 0x1C);

            if ($al === 0x00) {
                // Return buffer size in BX (64 bytes / 64 = 1 block)
                $ma->write16Bit(RegisterType::EBX, 0x0001);
            }

            $runtime->option()->logger()->debug(sprintf('INT 10h AH=0x1C AL=0x%02X (Video State): stub', $al));
            return;
        }

        // Other unsupported functions - log and ignore
        $runtime->option()->logger()->debug(sprintf('INT 10h AH=0x%02X: unsupported function', $ah));
    }
}
